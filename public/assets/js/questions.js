(() => {
    'use strict';

    const form = document.querySelector('[data-question-form]');
    if (!form) {
        return;
    }

    const select = form.querySelector('[data-question-type-select]');
    const groups = {
        choice: ['multiple_choice', 'multiple_select'],
        true_false: ['true_false'],
        short_text: ['short_text'],
        numeric: ['numeric'],
    };

    const updateFields = () => {
        Object.entries(groups).forEach(([group, types]) => {
            form.querySelectorAll(`[data-question-fields="${group}"]`).forEach((element) => {
                element.hidden = !types.includes(select.value);
            });
        });
    };

    select.addEventListener('change', updateFields);
    updateFields();

    form.addEventListener('submit', () => {
        form.querySelectorAll('[data-rich-text-target]').forEach((target) => {
            const key = target.dataset.richTextTarget;
            const editorElement = form.querySelector(`[data-rich-text-key="${key}"]`);
            if (!editorElement) {
                return;
            }
            const editor = window.quizMyClassApp?.textEditor?.get(editorElement);
            if (editor) {
                target.value = editor.getValue();
            }
        });
    });
})();
