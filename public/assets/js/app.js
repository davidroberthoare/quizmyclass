(() => {
    'use strict';

    const app = new Framework7({
        el: '#app',
        name: 'QuizMyClass',
        theme: 'auto',
        darkMode: 'auto',
    });
    window.quizMyClassApp = app;

    if (!app) {
        throw new Error('Framework7 failed to initialize.');
    }
})();


// Any form with data-confirm asks first (activate, close, reset...).
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (!form || form.dataset.confirmed === '1') {
        return;
    }
    event.preventDefault();
    window.quizMyClassApp.dialog.confirm(form.dataset.confirm, 'Please confirm', () => {
        form.dataset.confirmed = '1';
        form.submit();
    });
});
