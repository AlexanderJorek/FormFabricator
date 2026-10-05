function (root) {
    /* The ALTCHA widget reads its texts from $altcha.i18n under its language attribute ("fabricator"). Registers the
       field's translated texts there, over the widget's English ones so a text it adds later still shows. The store is
       reactive: a widget already on the page switches over at once. */
    function register(widget) {
        var strings;
        try { strings = JSON.parse(widget.getAttribute('data-strings') || '{}'); } catch (_) { return; }
        var i18n = window.$altcha && window.$altcha.i18n;
        if (!i18n) return;
        i18n.set(widget.getAttribute('language') || 'fabricator', Object.assign({}, i18n.get('en') || {}, strings));
    }
    root.querySelectorAll('altcha-widget[data-strings]').forEach(function (widget) {
        if (window.$altcha) {
            register(widget);
        } else if (window.customElements) {
            /* The widget is a script module, so it can still be loading. */
            window.customElements.whenDefined('altcha-widget').then(function () { register(widget); });
        }
    });
}
