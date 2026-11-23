(() => {
    'use strict';

    const app = window.quizMyClassApp;
    const page = document.querySelector('[data-room-page]');
    if (!app || !page) {
        return;
    }

    const roomSlug = page.dataset.roomSlug;
    const csrfToken = page.dataset.roomCsrf;

    // ---- Copy the room link ----
    const copyButton = page.querySelector('[data-copy-link]');
    if (copyButton) {
        copyButton.addEventListener('click', () => {
            const path = page.querySelector('#room-link').dataset.path;
            const url = window.location.origin + path;
            const done = () => app.toast.create({
                text: 'Link copied',
                closeTimeout: 2000,
                position: 'center',
            }).open();
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(done, () => app.dialog.alert(url, 'Copy this link'));
            } else {
                app.dialog.alert(url, 'Copy this link');
            }
        });
    }

    // ---- Per-question review menu (owner) ----
    const moderateForm = document.querySelector('#moderate-form');
    const moveForm = document.querySelector('#move-form');
    const submitWith = (form, fields) => {
        Object.entries(fields).forEach(([name, value]) => {
            form.querySelector(`[name="${name}"]`).value = value;
        });
        form.submit();
    };

    document.querySelectorAll('[data-question-menu]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!moderateForm || !moveForm) {
                return;
            }
            const id = button.dataset.questionId;
            const current = button.dataset.status;
            const setStatus = (status) => () => submitWith(moderateForm, { question_id: id, status });
            const move = (direction) => () => submitWith(moveForm, { question_id: id, direction });

            const statusButtons = [
                ['approved', 'Approve'],
                ['rejected', 'Reject'],
                ['hidden', 'Hide from students'],
                ['draft', 'Return to draft'],
            ].filter(([status]) => status !== current).map(([status, text]) => ({
                text,
                color: status === 'rejected' ? 'red' : (status === 'approved' ? 'green' : undefined),
                onClick: setStatus(status),
            }));

            const moveButtons = [];
            if (button.dataset.first !== '1') {
                moveButtons.push({ text: 'Move up', onClick: move('up') });
            }
            if (button.dataset.last !== '1') {
                moveButtons.push({ text: 'Move down', onClick: move('down') });
            }

            // One group: F7 puts a large gap between groups, which looked broken around "Move".
            const buttons = [{ text: 'Review question', label: true }, ...statusButtons];
            if (moveButtons.length > 0) {
                buttons.push({ text: 'Order', label: true }, ...moveButtons);
            }
            const groups = [buttons, [{ text: 'Cancel', color: 'red' }]];

            app.actions.create({
                buttons: groups,
                convertToPopover: true,
                targetEl: button,
            }).open();
        });
    });

    // ---- Drag to reorder (owner, editing mode) ----
    const sortable = document.querySelector('[data-question-sortable]');
    if (!sortable || sortable.dataset.sortableOwner !== '1' || !roomSlug || !csrfToken) {
        return;
    }

    app.sortable.enable(sortable);

    const fail = () => app.dialog.alert('The question could not be reordered. Reload the page and try again.', 'Reorder');

    app.on('sortableSort', (itemEl, data, listEl) => {
        if (listEl !== sortable) {
            return;
        }
        const questionId = itemEl?.dataset.questionId;
        if (!questionId) {
            return;
        }
        const form = new URLSearchParams({
            csrf_token: csrfToken,
            room_slug: roomSlug,
            question_id: questionId,
            target_position: String(Number(data?.to ?? 0) + 1),
        });
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
                fail();
            }
        }).catch(fail);
    });
})();
