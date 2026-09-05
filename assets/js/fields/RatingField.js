function (root) {
    root.querySelectorAll('.fabricator-rating-group').forEach(function (group) {
        var stars = Array.from(group.querySelectorAll('.fabricator-rating-star'));
        function highlight(val) {
            stars.forEach(function (star) {
                var n    = parseInt(star.dataset.star, 10);
                var full = n <= Math.floor(val);
                var half = !full && val % 1 !== 0 && n === Math.ceil(val);
                star.classList.toggle('fabricator-rating-star--full', full);
                star.classList.toggle('fabricator-rating-star--half', half);
            });
        }
        function restoreChecked() {
            var checked = group.querySelector('input:checked');
            highlight(checked ? parseFloat(checked.value) : 0);
        }
        group.addEventListener('mouseover', function (e) {
            var zone = e.target.closest('.fabricator-rating-zone');
            if (!zone) return;
            var radio = zone.querySelector('input[type="radio"]');
            if (radio) highlight(parseFloat(radio.value));
        });
        group.addEventListener('mouseleave', restoreChecked);
        group.querySelectorAll('.fabricator-rating-zone').forEach(function (zone) {
            var radio = zone.querySelector('input[type="radio"]');
            if (!radio) return;
            zone.addEventListener('click', function () {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
                restoreChecked();
            });
        });
        restoreChecked();
    });
}