// Gestion des notifications
function notification(sucess,header,body) {
	// Création de l'alerte
    $alert = $('<div class="alert alert-dismissable"><button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button></div>');
	// Ajout du titre
	$('<div style="font-weight:bold;"></div>')
		.text(header)
		.appendTo($alert);
	// Ajout des différents messages
	if(!$.isArray(body)) {
		body = [body];
	}
	$(body).each(function (i, message) {
		$('<div></div>')
			.html(message)
			.appendTo($alert);
    });
	// Ajout de la bonne classe
    if (sucess) {
        $alert.addClass('alert-success');
    } else {
        $alert.addClass('alert-danger');
    }
	// Positionement avant le tableau
	$("body > .container").prepend($alert);
}

// Factorisation de la fonction Ajax d'appel à l'API
function apiCall(method,url,data) { // To be used only when a payload shall be sent
	return $.ajax({
		url: "api.php"+url,
		type: method,
		processData: false,
		contentType: "application/json; charset=utf-8",
		data: JSON.stringify(data)
	});
}

// Replace text in element or value in src attribute
(function($) {
	$.fn.replaceText = function(search,replace) {
		return this.each(function() {
			$(this).find(":contains("+search+")")
				.filter(function() {return $(this).children().length === 0;})
				.text(replace);
			$(this).find("[data-src*='"+search+"']").each(function(i,el) {
				$(el).attr("src",$(el).data("src").replace(search,replace));
			});
		});
	};
}(jQuery));

$(document).ready(function () {
	// Gestion centralisée des erreurs sur requêtes Ajax
	$(document).ajaxError(function( event, jqxhr, settings, thrownError ) {
		try {
			var answer = $.parseJSON(jqxhr.responseText);
		} catch (e) {
			var answer = jqxhr.responseText;
		}
		notification(false,"Erreur",answer);
	});

	///////////////////////////////
	//  Refresh events handlers  //
	///////////////////////////////

	// Packages setup list
	$("#packages_setup").on("refresh",function(event) {
		var $this = $(this);
		$this.find(">:visible").remove();
		$.get("api.php/packages/setup", function(answer) {
      $("#tab_build").find(".alert").toggleClass("hidden",(answer.length != 0));
			$(answer).each(function(i,package) {
				$package_setup = $("#package_setup_"+package.valid+"_template").clone().removeAttr("id").removeClass("hidden")
					.replaceText("PATH",package.path)
					.data("files",{path:package.path});
				if (package.valid){
					$package_setup
						.replaceText("HEADER",package.appname+" v"+package.version)
						.replaceText("DESCRIPTION",package.description);
					if (package.relatedFile)
						$package_setup.data("files").relatedFile = package.relatedFile;
				} else {
					$package_setup.replaceText("ERROR",package.error);
				}
				$package_setup.appendTo($this);
			});
		});
	});

	// Packages list
	$("#packages").on("refresh",function(event) {
		var $this = $(this);
		$this.find(">:not([id])").remove();
		$.get("api.php/packages", function(answer) {
      $("#tab_install").find(".alert").toggleClass("hidden",(answer.length != 0));
			$(answer).each(function(i,package) {
				$package = $("#package_template").clone().removeAttr("id").removeClass("hidden")
					.replaceText("PATH",package.path)
					.data("path",package.path);
				if (package.path == $this.data("new")) {
					$package.find(".label").removeClass("hidden");
				}
				$package.appendTo($this);
			});
		});
	});

	// Apps list
	$("#apps").on("refresh",function(event) {
		var $this = $(this);
		$this.find(">:visible").remove();
		$.get("api.php/apps", function(answer) {
      $("#tab_manage").find(".alert").toggleClass("hidden",(answer.length != 0));
			$(answer).each(function(i,appname) {
				$app = $("#app_template").clone().removeAttr("id").removeClass("hidden")
					.replaceText("APPNAME",appname)
					.data("name",appname)
					.appendTo($this);
			});
		});
	});

	// Log file
	$("#log").on("refresh",function(event) {
		var $this = $(this);
		$this.empty();
		$.get("api.php/log", function(answer) {
			$(answer).each(function(i,line) {
				$this.append("<div>"+line+"</div>");
			});
		});
	});

	////////////////////////
	//  Actions handlers  //
	////////////////////////

	$("body")
		.on("click",".glyphicon-refresh", function(event) { // Refresh
			event.preventDefault();
			var selector = $(this).parent().attr("href");
			$(selector).trigger("refresh");
		})
		.on("click",'a[href="#update"]', function(event) { // Update files
			event.preventDefault();
			$.ajax({
				url: "api.php/apps/"+$(this).parentsUntil("#apps").last().data("name")+"/files/all",
				type: "PUT"
			});
		})
		.on("click",'a[href="#delete"]', function(event) { // Delete files
			event.preventDefault();
			$.ajax({
				url: "api.php/apps/"+$(this).parentsUntil("#apps").last().data("name")+"/files/"+$(this).data("type"),
				type: "DELETE"
			});
		})
		.on("click",'a[href="#install"]', function(event) { // Install package
			event.preventDefault();
			apiCall("POST","/apps",$(this).parentsUntil("#packages").last().data("path"));
		})
		.on("click",'a[href="#build"]', function(event) { // Build package
			event.preventDefault();
			apiCall("POST","/packages?method=serverSetupFile",$(this).parentsUntil("#packages_setup").last().data("files").path)
				.done(function(answer) {
					$("#packages").data("new",answer);
					$("#packages").trigger("refresh");
				});
		})
		.on("click",'a[href="#save"]', function(event) { // Save modified file
			event.preventDefault();
			$.ajax({
				url: $("#files>.active").data("url"),
				type: "PUT",
				processData: false,
				data: editor.getValue()
			})
			.done(function(answer) {
				$("#packages_setup").trigger("refresh");
			});
		})
    .on("submit","#tab_build > form", function(event) { // Create set-up folder
      event.preventDefault();
      $input = $(this).find("input");
      apiCall("POST","/packages/setup",$input.val())
        .done(function(answer) {
          $("#packages_setup").trigger("refresh");
          $input.val("");
        });
    });

	////////////////////////
	//    File editing    //
	////////////////////////
	var editor = ace.edit("file_content");
	editor.setTheme("ace/theme/chrome");
	editor.getSession().setUseWrapMode(true);

	$("#editFiles")
		.on('show.bs.modal', function(e) { // Customize modal
			var type = $(e.relatedTarget).data("type");
			switch(type) {
				case "conf":
				case "setup":
					var appname = $(e.relatedTarget).parentsUntil("#apps").last().data("name");
					$(this).find(".modal-title").text(appname+" "+type+" files");
					$.get("api.php/apps/"+appname+"/files/"+type, function(files) {
						$(files).each(function(i,file) {
							$("#file_name_template").clone().removeAttr("id").removeClass("hidden")
								.replaceText("FILENAME",file)
								.data("url","api.php/apps/"+appname+"/files/"+type+"/"+file)
								.appendTo("#files");
						});
					});
					break;
				case "package":
					$(this).find(".modal-title").text("Package setup files");
					$item = $(e.relatedTarget).parentsUntil("#packages_setup").last();
					$.each($item.data("files"),function(i,file) {
						$("#file_name_template").clone().removeAttr("id").removeClass("hidden")
							.replaceText("FILENAME",file)
							.data("url","api.php/packages/setup?file="+file)
							.appendTo("#files");
					});
					break;
			}
		})
		.on('shown.bs.modal', function(e) {$('#files>:visible:first').tab("show");}) // Activate tab
		.on('hide.bs.modal', function(e) { // Reset modal
			$(this).find(".modal-title").empty();
			$('#files>:visible').remove();
			editor.setValue("",-1);
		});

	// Retrieve tab content
	$('#files').on('shown.bs.tab','>', function(e) {
		$url = $(e.target).data("url");
		var mode = ace.require("ace/ext/modelist").getModeForPath($url).mode;
		editor.getSession().setMode(mode);
		$.get($url, function(content) {editor.setValue(content,-1);});
	});

	////////////
	//  Init  //
	////////////
	$("#packages_setup").trigger("refresh");
	$("#packages").trigger("refresh");
	$("#apps").trigger("refresh");
	$("#log").trigger("refresh");
});
