(() => {
    'use strict';

    const app = new Framework7({
        el: '#app',
        name: 'QuizMyClass',
        theme: 'auto',
    });
    window.quizMyClassApp = app;

    if (!app) {
        throw new Error('Framework7 failed to initialize.');
    }
})();

