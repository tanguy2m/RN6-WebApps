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
		});

	////////////
	//  Init  //
	////////////
	$("#packages_setup").trigger("refresh");
	$("#packages").trigger("refresh");
	$("#log").trigger("refresh");
});
