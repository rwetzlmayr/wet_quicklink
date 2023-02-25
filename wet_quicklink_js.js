/**
 * wet_quicklink: A Textpattern link builder plugin
 *
 * @author Robert Wetzlmayr
 * @link http://awasteofwords.com/help/wet_quicklink
 * @version 0.7
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
		menu += "<label for='ql-search'>"+wet_quicklink.gTxt.search+":</label>";
		menu += "<input type='text' class='edit' size='15' id='ql-search' style='margin-right: 1em;' value='"+
			wet_quicklink.search+"' />";

		menu += "<input type='submit' class='smallerbox' id='ql-rev' class='smallerbox' value='&lt;' />";
		menu += "<span id='pager' style='padding: 0 0.4em;'></span>";
		menu += "<input type='submit' class='smallerbox' id='ql-fwd' value='&gt;' />";

		menu += "<label for='ql-pattern' style='margin-left: 2em;'>"+wet_quicklink.gTxt.permlink_mode+
			":</label><select id='ql-pattern'" + (wet_quicklink.changeKeyPrivs ? '' : ' disabled="disabled"') + ">";
		for(key in wet_quicklink.patterns) {
			menu += "<option value='"+key+"'>"+key+"</option>";
		}
		menu += "</select>";
		menu += "<a id='ql-help' href='http://awasteofwords.com/help/wet_quicklink' target='_blank'>"+
			wet_quicklink.gTxt.help+"</a>";
		menu += "<input type='submit' class='smallerbox' id='ql-close' value='&#215;' />";
		menu += "</form>";
		wet_quicklink.pattern = wet_quicklink.patterns[this.currKey] || wet_quicklink.patterns["Textile"];

	   	$("body").append("<div id='quicklink'>"+menu+
			"<div><table id='list'></table></div><p id='quicklink-msg'>"+wet_quicklink.gTxt.loading+"</p></div>");

		// preselect pattern option from saved cookie
		try {
			$("#ql-pattern option[value='"+this.currKey+"']")[0].selected = true;
		} catch(e) {}
		// waiting for the master's voice
	 	$("#quicklink").hide();
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
	    			var table = "<tr><th class='ql-id'>id</th>"+
					"<th class='ql-title'><a rel='title' href='#'>"+wet_quicklink.gTxt.title+"</a></th>"+
					"<th class='ql-teaser'><a rel='body_html' href='#'>"+wet_quicklink.gTxt.body+"</a></th>"+
					"<th class='ql-posted'><a rel='posted' href='#'>"+wet_quicklink.gTxt.posted+"</a></th>"+
					"<th class='ql-lastmod'><a rel='lastmod' href='#'>"+wet_quicklink.gTxt.modified+"</a></th>"+
					"<th class='ql-section'><a rel='section' href='#'>"+wet_quicklink.gTxt.section+"</a></th>"+
					"<th class='ql-category1'><a rel='category1' href='#'>"+wet_quicklink.gTxt.category1.replace(/ /g,"&#160;")+"</a></th>"+
					"<th class='ql-category2'><a rel='category2' href='#'>"+wet_quicklink.gTxt.category2.replace(/ /g,"&#160;")+"</a></th>"+
					"<th class='ql-url'><a rel='url' href='#'>url</a></th></tr>";

				// parse the XML response
				$("article", xml).each (
					function(i) {
		    			// paint one article row
		    			table += "<tr" +
						"><td class='ql-id'>" + $("id", this).text() +
						"</td><td class='ql-title'>" +"<a href='#'>" +
							wet_quicklink.htmlspecialchars($("title", this).text()) +"</a>"+
						"</td><td class='ql-teaser'>" +
							wet_quicklink.htmlspecialchars($("teaser", this).text())+ "&#8230;" +
						"</td><td class='ql-posted'>" +
							$("posted", this).text().replace(/ [0-9]*:[0-9]*:[0-9]*/, "") + // remove hh:mm:ss
		    			"</td><td class='ql-lastmod'>" +
							$("lastmod", this).text().replace(/ [0-9]*:[0-9]*:[0-9]*/, "") +
						"</td><td class='ql-section'>" +
							wet_quicklink.htmlspecialchars($("section", this).text()) +
						"</td><td class='ql-category1'>" +
							wet_quicklink.htmlspecialchars($("category[level='1']", this).text()) +
						"</td><td class='ql-category2'>" +
							wet_quicklink.htmlspecialchars($("category[level='2']", this).text()) +
						"</td><td class='ql-url'>" + $("permlink", this).text() +
						"</td></tr>";
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

				// zebra stripes
				$('#list tr:odd').addClass('odd');

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
						var cell = $(this).parent(); // td > a

						var id = cell.siblings(".ql-id").text();  // the article id
						tag = tag.replace(/{id}/gi, id);

						var url = cell.siblings(".ql-url").text();  // the url
						tag = tag.replace(/{url}/gi, url);

						var title = cell.children("a").text();  // the title
						tag = tag.replace(/{title}/gi, title);

						tag = tag.replace(/{text}/gi, getSel(t));
						edInsertContent(t, tag);
						$("#quicklink").hide();
						return false;
					}
				);
	 		}
		);
	},

	// launch overlay on menu click
	hook : function () {
		$('#insert-link').click(
			function() {
				wet_quicklink.refresh();
				$("#quicklink").show();
				return false;
			}
		);
	},

	// add behaviours
	behaviours : function() {
		$('input#ql-close').click(
			function() {
				$("#quicklink").hide();
				return false;
			}
		);

		$('input#ql-fwd').click(
			function() {
				var here = wet_quicklink.firstrow;
				if(wet_quicklink.firstrow + wet_quicklink.rows < wet_quicklink.maxrows) {
					wet_quicklink.firstrow += wet_quicklink.rows;
				}
				if(here != wet_quicklink.firstrow) wet_quicklink.refresh();
				return false;
			}
		);

		$('input#ql-rev').click(
			function() {
				var here = wet_quicklink.firstrow;
				if(wet_quicklink.firstrow >= wet_quicklink.rows) {
					wet_quicklink.firstrow -= wet_quicklink.rows;
				}
				if(here != wet_quicklink.firstrow) wet_quicklink.refresh();
				return false;
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
			function() {
				var currKey = this.options[this.options.selectedIndex].value;
				wet_quicklink.pattern = wet_quicklink.patterns[currKey];
				sendAsyncEvent(
					{
						wet_quicklink: 'save_key',
						key: currKey
					}
				);
				return false;
			}
		);
	},

	htmlspecialchars : function (s) {
		s = s.replace(/&/g,"&amp;");
		s = s.replace(/</g,"&lt;");
		s = s.replace(/>/g,"&gt;");
		s = s.replace(/"/g,"&quot;");
		return s;
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
		var targets = new Array("title", "body", "excerpt",
			"custom-1", "custom-2", "custom-3", "custom-4", "custom-5",
			"custom-6", "custom-7", "custom-8", "custom-9", "custom-10",
			"keywords");
		var i, l = targets.length;
		// click handler stores "active" textarea/input element
		for (i = 0; i < l; i++) {
			var t = $("#"+targets[i]);
			if(t.length == 0) continue;
			t.click(function() {
					wet_quicklink.target = this;
				}
			);
		}
	}
);
