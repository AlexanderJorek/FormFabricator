function (root) {
    root.querySelectorAll('.fabricator-example-widget').forEach(function (widget) {
        /* Idempotent: front.js re-runs every field init after each successful submit, so without this guard every
           submit would attach one more click handler. */
        if (widget._fabricatorExampleInited) return;
        widget._fabricatorExampleInited = true;
        widget.addEventListener('click', function () {
            // ... interaction logic
        });
    });
}
