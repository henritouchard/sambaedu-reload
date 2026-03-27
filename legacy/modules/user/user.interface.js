const body = document.body;
const menuLinks = document.querySelectorAll(".admin-menu a");
const collapseBtn = document.querySelector(".admin-menu .collapse-btn");
const toggleMobileMenu = document.querySelector(".toggle-mob-menu");
const collapsedClass = "collapsed";

/*
 * Toggle password eye/slash *
 */
var parent = document.querySelector(".parent");
if (parent != null) {
	parent.addEventListener("click", event => {
		if (event.target.matches("span")) {
			var spanElm = event.target;
			var inputElm = spanElm.previousElementSibling;
			showHide(inputElm, spanElm);
		}
	});
}
function showHide(input, showText) {
	if (input.getAttribute("type") === "password") {
		input.setAttribute("type", "text");
		showText.classList.remove("fa-eye");
		showText.classList.add("fa-eye-slash");
	} else {
		input.setAttribute("type", "password");
		showText.classList.remove("fa-eye-slash");
		showText.classList.add("fa-eye");
	}
}



function collapsed() {
	var collaps = getCookie("collapsed");
	if (collaps == "true") {
		collapseBtn.setAttribute("aria-expanded", "false");
		collapseBtn.setAttribute("aria-label", "collapse menu");
		body.classList.toggle(collapsedClass);
	}
	if (collaps == "false") {
		collapseBtn.setAttribute("aria-expanded", "true");
		collapseBtn.setAttribute("aria-label", "expand menu");
	}
	// body.classList.toggle(collapsedClass);
}

function getCookie(cname) {
	var name = cname + "=";
	var decodedCookie = decodeURIComponent(document.cookie);
	var ca = decodedCookie.split(';');
	for (var i = 0; i < ca.length; i++) {
		var c = ca[i];
		while (c.charAt(0) == ' ') {
			c = c.substring(1);
		}
		if (c.indexOf(name) == 0) {
			return c.substring(name.length, c.length);
		}
	}
	return "";
}

function showPasswd() {
	var x = document.getElementById("password");
	if (x.type === "password") {
		x.type = "text";
	} else {
		x.type = "password";
	}
}

if (collapseBtn) {
	collapseBtn.addEventListener("click", function () {
		if (this.getAttribute("aria-expanded") == "true") {
			this.setAttribute("aria-expanded", "false");
			this.setAttribute("aria-label", "collapse menu");
			document.cookie = 'collapsed=true';
		} else {
			this.setAttribute("aria-expanded", "true");
			this.setAttribute("aria-label", "expand menu")
			document.cookie = 'collapsed=false';
		}
		body.classList.toggle(collapsedClass);
	});

	toggleMobileMenu.addEventListener("click", function () {
		this.getAttribute("aria-expanded") == "true" ?
			this.setAttribute("aria-expanded", "false") :
			this.setAttribute("aria-expanded", "true");
		this.getAttribute("aria-label") == "open menu" ?
			this.setAttribute("aria-label", "close menu") :
			this.setAttribute("aria-label", "open menu");
		body.classList.toggle("mob-menu-opened");
	});
}

for (const link of menuLinks) {
	link.addEventListener("mouseenter", function () {
		body.classList.contains(collapsedClass) &&
			window.matchMedia("(min-width: 768px)").matches ?
			this.setAttribute("title", this.textContent) :
			this.removeAttribute("title");
	});
}
//


/*
 * Vérification des champs du mot de passe
 * 
 */
function PasswordModifyEvents() {
	var dummy_text = document.getElementById("dummy_text");
	var dummy1_text = document.getElementById("dummy1_text");
	var password = document.getElementById('password').value;
	var dummy = document.getElementById('dummy').value;
	var dummy1 = document.getElementById('dummy1').value;

	maxPWlength = 20;

	if (!minPWlength) {
		minPWlength = 8;
	}
	if ((password.length == 0) || (dummy.length == 0)) {
		dummy_text.innerHTML = "";
		return;
	}
	strength = 0;
	if (new RegExp("[A-Z]").test(dummy)) {
		maj = "<span style='color : green;'>majuscule</span>";
		strength += 1;
	} else {
		maj = "<span style='color : red;'>majuscule</span>";
	}
	if (new RegExp("[a-z]").test(dummy)) {
		min = "<span style='color : green;'>minuscule</span>";
		strength += 1;
	} else {
		min = "<span style='color : red;'>minuscule</span>";
	}
	if (new RegExp("[0-9]").test(dummy)) {
		digit = "<span style='color : green;'>chiffre</span>";
		strength += 1;
	} else {
		digit = "<span style='color : red;'>chiffre</span>";
	}
	if (new RegExp("[_$&+,:;=?@#|'<>\.^*()%!-]").test(dummy)) {
		specchar = "<span style='color : green;'>caractères spéciaux</span>";
		strength += 1;
	} else {
		specchar = "<span style='color : red;'>caractères spéciaux</span>";
	}
	if ((dummy.length > minPWlength - 1) && (dummy.length < maxPWlength + 1)) {
		pwlength = "green";
	} else {
		pwlength = "red";
	}

	if ((pwlength == "green") && (strength > 3)) {
		ValidPass = 1;
		IsGood = "<span style='color :green;'>Bon mot de passe.</span>";
	} else {
		IsGood = "<span style='color :red;'>Mot de passe trop faible.</span>";
		ValidPass = 0;
	}

	dummy_text.innerHTML = IsGood
		+ "<br />"
		+ "- Le mot de passe doit contenir au moins un caractère pour chacun de ces types : "
		+ "<br />"
		+ maj + ", " + min + ", " + digit + ", " + specchar
		+ "."
		+ "<br />"
		+ "- <span style='color : " + pwlength + ";'>Le mot de passe doit contenir entre " + minPWlength + " et " + maxPWlength + " caractères.</span>";
	EqualsPass = 0;
	if (dummy1) {
		if (dummy == dummy1) {
			dummy1_text.style.color = 'green';
			dummy1_text.innerHTML = 'Mots de passe identiques.';
			EqualsPass = 1;
		} else {
			dummy1_text.style.color = 'red';
			dummy1_text.innerHTML = 'Mots de passe différents.';
			EqualsPass = 0;
		}
	} else {
		dummy1_text.innerHTML = "";
	}
	if ((ValidPass == 1) && (EqualsPass == 1)) {
		document.getElementById("chpwdValid").disabled = false;
	} else {
		document.getElementById("chpwdValid").disabled = true;
	}
}


