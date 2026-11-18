(() => {
    'use strict';

    const app = window.quizMyClassApp;
    const roomSlug = document.querySelector('[data-room-slug]')?.dataset.roomSlug;
    const csrfToken = document.querySelector('[data-room-csrf]')?.dataset.roomCsrf;
    const sortable = document.querySelector('[data-question-sortable]');
    if (!app || !sortable || !roomSlug || !csrfToken) {
        return;
    }
    if (sortable.dataset.sortableOwner !== '1') {
        return;
    }

    app.sortable.enable(sortable);

    const savePosition = (listItem, data) => {
        const questionId = listItem?.dataset.questionId;
        if (!questionId) {
            return;
        }
        const targetPosition = Number(data?.to ?? 0) + 1;

        const form = new URLSearchParams();
        form.set('csrf_token', csrfToken);
        form.set('room_slug', roomSlug);
        form.set('question_id', questionId);
        form.set('target_position', String(targetPosition));

        fetch('/questions/move.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: form.toString(),
        }).then((response) => {
            if (!response.ok) {
                app.dialog.alert('The question could not be reordered.', 'Room question order');
            }
        }).catch(() => {
            app.dialog.alert('The question could not be reordered.', 'Room question order');
        });
    };

    app.on('sortableSort', (itemEl, data, listEl) => {
        if (listEl !== sortable) {
            return;
        }
        savePosition(itemEl, data);
    });
})();
