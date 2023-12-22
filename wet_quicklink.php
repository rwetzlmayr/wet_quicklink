<?php

$plugin['version'] = '4.8';
$plugin['author'] = 'Robert Wetzlmayr';
$plugin['author_uri'] = 'https://wetzlmayr.at/';
$plugin['description'] = 'Pick and insert site internal links into articles';
$plugin['type'] = 3;
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
wet_quicklink_insert_link => Insert Link
wet_quicklink_loading => Loading…
#@language de-de
wet_quicklink_insert_link => Link einfügen
wet_quicklink_loading => Laden…
EOT;

if (!defined('txpinterface'))
	@include_once('zem_tpl.php');

if (0) {
?>
# --- BEGIN PLUGIN HELP ---

h3. Quick internal link builder for Textpattern articles

*wet_quicklink* is a plugin for Textpattern which extends the article edit screen to add an easy method for defining site internal links to other articles.

Candidate articles can be chosen by a wide variety of methods, ranging from sorting by posting date or assigned section, to a live content search inside the articles' body or title.

h4. Usage

# Textpattern 4.6+ is required, wet_quicklink will _not_ work with any prior versions.
# Install both @wet_quicklink@ and @wet_peex@ as a Textpattern plugin.
# Done. If all went well, the "Content > Edit":./?event=article screen will sport a new menu entry called "Insert Link" located just above "Advanced".

Visit the "plugin help page":http://awasteofwords.com/help/wet_quicklink for more detailled instructions.

h4. Localization

@wet_quicklink@ supports its user interface in your language. English and German translations are included in the plugin itself.

For other languages, use the sample textpack below like so:

# Change the language code from @en-gb@ to the one matching your site's language settings
# Replace the two English texts right to the @=>@ symbols with your own
# Paste it into the Textpack box at the "Admin > Preferences > Language":?event=prefs&step=list_languages#text_uploader screen

This is a sample textpack to get you started:

bc. #@admin
#@language en-gb
wet_quicklink_insert_link => Insert Link
wet_quicklink_loading => Loading…

h4. Licence and Disclaimer

This plug-in is released under the "Gnu General Public Licence":http://www.gnu.org/licenses/gpl.txt.

# --- END PLUGIN HELP ---

<?php
}

# --- BEGIN PLUGIN CODE ---

// launch the UI overlay
register_callback('wet_quicklink_menu', 'article_ui', 'extend_col_1');
// build the UI overlay
register_callback('wet_quicklink_clutch', 'article');
register_callback('wet_quicklink_css', 'admin_side', 'head_end');
register_callback('wet_quicklink_save_key', 'wet_quicklink', 'save_key');

// Don't let the unwashed masses fiddle with the way we add links
add_privs('wet_quicklink.preferences', '1,2');

// serve assorted resources
switch(gps('wet_rsrc')) {
	case 'quicklink_js':
		wet_quicklink_js();
		break;
	default:
		break;
}

// AJAX handler
switch(ps('wet_quicklink')) {
	case 'save_key':
		wet_quicklink_safe_key();
		break;
	default:
		break;
}

/**
 * Insert a menu entry in the first sidebar
 */
function wet_quicklink_menu()
{
	// add a link in the left column to launch the overlay
	return graf(href(gTxt('wet_quicklink_insert_link'), '#insert-link', array('id' => 'insert-link', 'data-txp-dialog' => '#quicklink')));
}

/**
 * Inject a few JS constants and pull in the JS worker file near the end of the page
 */
function wet_quicklink_clutch($event, $step)
{
	global $app_mode;
	if ($app_mode == 'async') return;

	echo script_js("?wet_rsrc=quicklink_js", TEXTPATTERN_SCRIPT_URL);
	gTxtScript(array(
			'title',
			'body',
			'category1',
			'category2',
			'section',
			'posted',
			'modified',
			'permlink_mode',
			'help',
			'search',
			'wet_quicklink_loading')
	);

	/*
	Link building patterns for various markup methods

	Patterns are used literally besides some special place holders:
	{id}: article id
	{title}: article title
	{url}: fully qualified article URL per current URL mode
	{text}: selected text from insertion target
	*/
	$patterns = json_encode(array (
		'Textile' 		=> '"{text}({title})":{url}',
		'txp:permlink' 	=> '<txp:permlink id="{id}" title="{title}">{text}</txp:permlink>',
		'txp:wet_link' 	=> '<txp:wet_link href="{id}">{text}</txp:wet_link>',
		'HTML' 			=> '<a href="{url}" title="{title}" rel="bookmark">{text}</a>',
		'URL' 			=> '{url}'
	));

	// Gather link markup preferences
	$curr_key = get_pref('wet_quicklink.key', 'txp:permlink');
	$change_key_privs = has_privs('wet_quicklink.preferences') ? 'true' : 'false';

	echo script_js( <<<CFG
wet_quicklink.patterns = $patterns;
wet_quicklink.currKey = "$curr_key";
wet_quicklink.changeKeyPrivs = $change_key_privs;

CFG
);
	echo tag('<!-- -->', 'div', array('class' => 'txp-dialog', 'id' => 'quicklink', 'title' => gTxt('wet_quicklink_insert_link')));
	require_plugin('wet_peex'); // won't help for loading wet_peex on time, but point out the lack of it to unwary users.
}

/**
 * AJAX endpoint: Safe current key as system preference
 */
function wet_quicklink_safe_key()
{
	if (has_privs('wet_quicklink.preferences')) {
		set_pref('wet_quicklink.key', doSlash(ps('key')), 'wet_foo', 2);
		send_xml_response();
	} else {
		send_xml_response(array('http-status' => '403 Forbidden'));
	}
}

/**
 * Serve JS resource, as either an embedded resource or from a file while in development
 */
function wet_quicklink_js()
{
    if (ob_get_length()) {
        while(@ob_end_clean());
    }
	header("Content-Type: text/javascript; charset=utf-8");
	header("Expires: ".date("r", time() + 3600));
	header("Cache-Control: public");
	die(<<<JS
/**
 * wet_quicklink: A Textpattern link builder plugin
 *
 * @author Robert Wetzlmayr
 * @link http://awasteofwords.com/help/wet_quicklink
 * @version 4.6.1
 */

var wet_quicklink = {
	pattern : "",
	currKey : "",
 	rows : 10,
	firstrow : 0,
	maxrows : 0,
	sortdir : "desc",
	crit : "lastmod",
	search : getCookie("wet_quicklink-search") || "",
	target : $("#body")[0], // insert links into article body unless focus changes

	paint : function () {
	   	// ui box
		var menu = "<form id='ql-menu'>";
		menu += "<label for='ql-search'>"+textpattern.gTxt('search')+":</label>";
		menu += "<input type='text' class='edit' size='15' id='ql-search' style='margin-right: 1em;' value='"+
			wet_quicklink.search+"' />";

		menu += "<input type='submit' class='smallerbox' id='ql-rev' class='smallerbox' value='&lt;' />";
		menu += "<span id='pager' style='padding: 0 0.4em;'></span>";
		menu += "<input type='submit' class='smallerbox' id='ql-fwd' value='&gt;' />";

		menu += "<label for='ql-pattern' style='margin-left: 2em;'>"+textpattern.gTxt('permlink_mode')+
			":</label><select id='ql-pattern'" + (wet_quicklink.changeKeyPrivs ? '' : ' disabled="disabled"') + ">";
		for(key in wet_quicklink.patterns) {
			menu += "<option value='"+key+"'>"+key+"</option>";
		}
		menu += "</select>";
		menu += "<a id='ql-help' href='http://awasteofwords.com/help/wet_quicklink' target='_blank'>"+
			textpattern.gTxt('help')+"</a>";
		menu += "</form>";
		wet_quicklink.pattern = wet_quicklink.patterns[this.currKey] || wet_quicklink.patterns["Textile"];

	   	$("#quicklink").html(menu +
			"<div><table id='list'></table></div><p id='quicklink-msg'>"+textpattern.gTxt('loading')+"</p>");

		// preselect pattern option from saved cookie
		try {
			$("#ql-pattern option[value='"+this.currKey+"']")[0].selected = true;
		} catch(e) {}
		// waiting for the master's voice
	 	$("#quicklink-msg").hide();
		this.behaviours();
	},

	// the worker function
	refresh : function () {
		var box = $("#list");
		box.empty();
		wet_quicklink.loading(true);
		$.get(
	 		"",
	 		{ wet_peex: "article", limit: wet_quicklink.rows.toString(), offset: wet_quicklink.firstrow.toString(),
				dir: wet_quicklink.sortdir, sort: wet_quicklink.crit, search: wet_quicklink.search },
			function(xml){
				wet_quicklink.maxrows = $("articles", xml).attr("count");

				$("#pager").html(Math.ceil(wet_quicklink.firstrow/wet_quicklink.rows + 1).toString() + "/"
					+ Math.ceil(wet_quicklink.maxrows/wet_quicklink.rows).toString());

	    			// paint the article table header
	    			var table = "<tr>"+
					"<th class='ql-title'><a rel='title' href='#'>"+textpattern.gTxt('title')+"</a></th>"+
					"<th class='ql-teaser'><a rel='body_html' href='#'>"+textpattern.gTxt('body')+"</a></th>"+
					"<th class='ql-posted'><a rel='posted' href='#'>"+textpattern.gTxt('posted')+"</a></th>"+
					"<th class='ql-lastmod'><a rel='lastmod' href='#'>"+textpattern.gTxt('modified')+"</a></th>"+
					"<th class='ql-section'><a rel='section' href='#'>"+textpattern.gTxt('section')+"</a></th>"+
					"<th class='ql-category1'><a rel='category1' href='#'>"+textpattern.gTxt('category1').replace(/ /g,"&#160;")+"</a></th>"+
					"<th class='ql-category2'><a rel='category2' href='#'>"+textpattern.gTxt('category2').replace(/ /g,"&#160;")+"</a></th>"+
					"</tr>";

				// parse the XML response
				$("article", xml).each (
					function(i) {
		    			// paint one article row
		    			var a = $('<a/>')
		    			    .attr('href', $("permlink", this).text())
		    			    .attr('data-id', $("id", this).text())
		    			    .html($("title", this).text());
		    			table += "<tr>" +
						"<td class='ql-title'>" + a[0].outerHTML + '</td>' +
						"<td class='ql-teaser'>" +
							textpattern.encodeHTML($("teaser", this).text())+ "&#8230;" + '</td>' +
						"<td class='ql-posted'>" +
							$("posted", this).text().replace(/ [0-9]*:[0-9]*:[0-9]*/, "") + '</td>' + // remove hh:mm:ss
		    			"<td class='ql-lastmod'>" +
							$("lastmod", this).text().replace(/ [0-9]*:[0-9]*:[0-9]*/, "") + '</td>' +
						"<td class='ql-section'>" +
							textpattern.encodeHTML($("section", this).text()) + '</td>' +
						"<td class='ql-category1'>" +
							textpattern.encodeHTML($("category[level='1']", this).text()) + '</td>' +
						"<td class='ql-category2'>" +
							textpattern.encodeHTML($("category[level='2']", this).text()) + '</td>' +
						"</tr>";
					}
				);

				// inject table into ui
	    			box.html(table);

				// table heads act as ui for sort direction
				$("#list tr th a")
					.click( function() {
						// revert sort order just the second time around
						if(wet_quicklink.crit == $(this).attr("rel")) {
							wet_quicklink.sortdir = (wet_quicklink.sortdir == "desc" ? "asc" : "desc");
						}
						// define sort criterion
						wet_quicklink.crit = $(this).attr("rel");
						wet_quicklink.firstrow = 0;
						wet_quicklink.refresh();
						return false;
					});

				// style table heads as indicators for sort direction
				$("#list tr th a[rel='"+wet_quicklink.crit+"']").addClass(wet_quicklink.sortdir);
				wet_quicklink.loading(false);

				/**
				 * Insert content at caret position
				 * @link http://alexking.org/blog/2003/06/02/inserting-at-the-cursor-using-javascript
				 */
				function edInsertContent(myField, myValue) {
					//IE support
					if (document.selection) {
						myField.focus();
						sel = document.selection.createRange();
						sel.text = myValue;
						myField.focus();
					}
					//MOZILLA/NETSCAPE support
					else if (myField.selectionStart || myField.selectionStart == '0') {
						var startPos = myField.selectionStart;
						var endPos = myField.selectionEnd;
						var scrollTop = myField.scrollTop;
						myField.value = myField.value.substring(0, startPos)
						              + myValue
				                      + myField.value.substring(endPos, myField.value.length);
						myField.focus();
						myField.selectionStart = startPos + myValue.length;
						myField.selectionEnd = startPos + myValue.length;
						myField.scrollTop = scrollTop;
					} else {
						myField.value += myValue;
						myField.focus();
					}
				}

				function getSel(myField) {
					//IE support
					if (document.selection) {
						return document.selection.createRange().text;
					}
					//MOZILLA/NETSCAPE support
					else if (myField.selectionStart || myField.selectionStart == '0') {
						var startPos = myField.selectionStart;
						var endPos = myField.selectionEnd;
						return myField.value.substring(startPos, endPos);
					} else {
						return "";
					}
				}

				// insert permlink on title click
				$('td.ql-title a').click(
					function() {
						// find the insertion target
						var t = wet_quicklink.target;
						// build tag
						var tag = wet_quicklink.pattern;
						var a = $(this);
						var title = a.text();
						var sel = getSel(t);
						if (sel.length == 0) {
							sel = title;
						}

						tag = tag.replace(/{id}/gi, a.data('id'));
						tag = tag.replace(/{url}/gi, a.attr('href'));
						tag = tag.replace(/{title}/gi, title);
						tag = tag.replace(/{text}/gi, sel);
						edInsertContent(t, tag);
						$("#quicklink").dialog('close');
						return false;
					}
				);
	 		}
		);
	},

	// launch overlay on menu click
	hook : function () {
		$('#insert-link').click(
			function(event) {
				event.preventDefault();
				wet_quicklink.refresh();
				$("#quicklink").dialog("option", "width", '50%');
				//$("#quicklink").dialog("option", "position", { my: "top right", at: "top right", of: window });
			}
		);
	},

	// add behaviours
	behaviours : function() {
		$('input#ql-close').click(
			function(event) {
				event.preventDefault();
				$("#quicklink").hide();
			}
		);

		$('input#ql-fwd').click(
			function(event) {
				event.preventDefault();
				var here = wet_quicklink.firstrow;
				if(wet_quicklink.firstrow + wet_quicklink.rows < wet_quicklink.maxrows) {
					wet_quicklink.firstrow += wet_quicklink.rows;
				}
				if(here != wet_quicklink.firstrow) wet_quicklink.refresh();
			}
		);

		$('input#ql-rev').click(
			function() {
				event.preventDefault();
				var here = wet_quicklink.firstrow;
				if(wet_quicklink.firstrow >= wet_quicklink.rows) {
					wet_quicklink.firstrow -= wet_quicklink.rows;
				}
				if(here != wet_quicklink.firstrow) wet_quicklink.refresh();
			}
		);

		$('input#ql-search').keyup(
			function() {
				if(this.value != wet_quicklink.search) {
					wet_quicklink.firstrow = 0;
					wet_quicklink.search = this.value;
					setCookie("wet_quicklink-search", this.value);

					try {
						window.clearTimeout(this.lazy);
					} catch(e) {}
					this.lazy = window.setTimeout(wet_quicklink.refresh, 750);
				}
			}
		);

		$('select#ql-pattern').click(
			function(event) {
				event.preventDefault();
				var currKey = this.options[this.options.selectedIndex].value;
				wet_quicklink.pattern = wet_quicklink.patterns[currKey];
				sendAsyncEvent(
					{
						wet_quicklink: 'save_key',
						key: currKey
					}
				);
			}
		);
	},

	status : function (s, is_ok) {
		var msg = $("#quicklink-msg");
		msg.html(s);
		if(is_ok) {
			msg.addClass("success");
		} /* @todo remove class later */
		msg.fadeIn("slow");
		msg.fadeOut("slow");
	},

	loading : function (is_on) {
		var msg = $("#quicklink-msg");
		msg.addClass("success");	 /* @todo remove class later */
		if(is_on) {
			msg.fadeIn("slow");
		} else {
			msg.fadeOut("slow");
		}
	}
};

$(document).ready(
	function(){
		wet_quicklink.hook();
		wet_quicklink.paint();

		// insertion targets
		var targets = "#title, #body, #excerpt, #custom-1, #custom-2, #custom-3, #custom-4, #custom-5, #custom-6, #custom-7, #custom-8, #custom-9, #custom-10, #keywords";
		$(body).on(targets, 'click', function() {
			wet_quicklink.target = this;
		});
	}
);
JS
);
}

function wet_quicklink_css()
{
	global $event, $app_mode;
	if ($event != 'article' || $app_mode == 'async') return;

	echo <<<CSS
<style>
div#quicklink div {
	overflow: auto;
}

div#quicklink #ql-help {
	position: absolute;
	right: 2.5em; top: 4px;
}

div#quicklink table {
	width: 100%;
	margin-top: 0.5em;
	table-layout: fixed;
}

div#quicklink td, div#quicklink th {
	overflow: hidden;
}

div#quicklink .ql-title, div#quicklink .ql-teaser {
	width: 15em;
}

div#quicklink .ql-section {
	width: 6em;
}

div#quicklink .ql-posted, div#quicklink .ql-lastmod {
	width: 7em;
}

div#quicklink .ql-category1, div#quicklink .ql-category2 {
	width: 8em;
}

div#quicklink tr:nth-child(odd) {
	background-color: #f8f8ff;
}

div#quicklink td {
	padding: 0.2em 0;
}

div#quicklink th {
	padding: 0.2em 0.2em;
}

div#quicklink th a.asc {
	background: transparent url(txp_img/arrowupdn.gif) no-repeat right 2px;
	padding-right: 14px;
}

div#quicklink th a.desc {
	background: transparent url(txp_img/arrowupdn.gif) no-repeat right -18px;
	padding-right: 14px;
}

#quicklink-msg {
	position: absolute;
	top: 0;
	right: 0;
	padding: 0.5em 2em;
}

#quicklink-msg.success {
	color: white;
	background-color: #8c8;
}

#quicklink-msg.failure {
	color: white;
	font-weight: bold;
	background-color: #f00;
}
</style>

CSS;
}

# --- END PLUGIN CODE ---
