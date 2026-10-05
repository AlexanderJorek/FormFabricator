function (f) {
    /* ALTCHA posts its solution in a hidden input inside its widget, empty until solved; reCAPTCHA in its
       g-recaptcha-response textarea, which exists only once the visitor has loaded it. */
    var answer = f.querySelector('altcha-widget input[type="hidden"], [name="g-recaptcha-response"]');
    return !answer || !answer.value.trim();
}
