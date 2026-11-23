(() => {
    'use strict';

    const form = document.querySelector('[data-question-form]');
    if (!form) {
        return;
    }

    const typeInput = form.querySelector('[data-question-type]');
    const options = form.querySelectorAll('[data-type-option]');
    const groups = {
        choice: ['multiple_choice', 'multiple_select'],
        true_false: ['true_false'],
        short_text: ['short_text'],
        numeric: ['numeric'],
    };
    const choiceList = form.querySelector('[data-choice-list]');
    const hint = form.querySelector('[data-choice-hint]');
    const MAX_CHOICES = 10;
    const MIN_CHOICES = 2;

    const rows = () => Array.from(choiceList.querySelectorAll('[data-choice-row]'));

    // Keep row numbers, labels and the remove buttons consistent after any change.
    const renumber = () => {
        const all = rows();
        all.forEach((row, index) => {
            const n = index + 1;
            row.querySelector('[data-choice-correct]').value = String(index);
            row.querySelector('[data-choice-correct]').setAttribute('aria-label', `Choice ${n} is correct`);
            const text = row.querySelector('[data-choice-text]');
            text.placeholder = `Choice ${n}`;
            text.setAttribute('aria-label', `Choice ${n} text`);
            const remove = row.querySelector('[data-choice-remove]');
            remove.setAttribute('aria-label', `Remove choice ${n}`);
            remove.disabled = all.length <= MIN_CHOICES;
        });
        const add = form.querySelector('[data-choice-add]');
        add.disabled = all.length >= MAX_CHOICES;
    };

    // Radios for single-answer questions, checkboxes when several answers may be correct.
    const syncCorrectInputs = (type) => {
        const multiple = type === 'multiple_select';
        let seenChecked = false;
        rows().forEach((row) => {
            const input = row.querySelector('[data-choice-correct]');
            input.type = multiple ? 'checkbox' : 'radio';
            const mark = row.querySelector('[data-choice-mark]');
            mark.classList.toggle('checkbox', multiple);
            mark.classList.toggle('radio', !multiple);
            mark.querySelector('i').className = multiple ? 'icon-checkbox' : 'icon-radio';
            if (!multiple && input.checked) {
                if (seenChecked) {
                    input.checked = false;
                }
                seenChecked = true;
            }
        });
        if (hint) {
            hint.textContent = multiple
                ? 'Tick every correct answer. Blank rows are ignored.'
                : 'Select the correct answer. Blank rows are ignored.';
        }
    };

    const setType = (type) => {
        typeInput.value = type;
        options.forEach((button) => {
            const active = button.dataset.typeOption === type;
            button.classList.toggle('button-active', active);
            button.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        Object.entries(groups).forEach(([group, types]) => {
            form.querySelectorAll(`[data-question-fields="${group}"]`).forEach((element) => {
                element.hidden = !types.includes(type);
            });
        });
        syncCorrectInputs(type);
    };

    options.forEach((button) => {
        button.addEventListener('click', () => setType(button.dataset.typeOption));
    });

    form.querySelector('[data-choice-add]').addEventListener('click', () => {
        const all = rows();
        if (all.length >= MAX_CHOICES) {
            return;
        }
        const clone = all[all.length - 1].cloneNode(true);
        clone.querySelector('[data-choice-text]').value = '';
        clone.querySelector('[data-choice-correct]').checked = false;
        choiceList.appendChild(clone);
        renumber();
        clone.querySelector('[data-choice-text]').focus();
    });

    choiceList.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-choice-remove]');
        if (!remove || rows().length <= MIN_CHOICES) {
            return;
        }
        remove.closest('[data-choice-row]').remove();
        renumber();
    });

    // Enter in a choice box adds the next choice instead of submitting the whole form.
    choiceList.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || !event.target.matches('[data-choice-text]')) {
            return;
        }
        event.preventDefault();
        const row = event.target.closest('[data-choice-row]');
        const next = row.nextElementSibling;
        if (next) {
            next.querySelector('[data-choice-text]').focus();
        } else {
            form.querySelector('[data-choice-add]').click();
        }
    });

    renumber();
    setType(typeInput.value);

    form.addEventListener('submit', (event) => {
        form.querySelectorAll('[data-rich-text-target]').forEach((target) => {
            const key = target.dataset.richTextTarget;
            const editorElement = form.querySelector(`[data-rich-text-key="${key}"]`);
            const editor = editorElement && window.quizMyClassApp?.textEditor?.get(editorElement);
            if (editor) {
                target.value = editor.getValue();
            }
        });
        const prompt = form.querySelector('[data-rich-text-target="prompt_html"]');
        const plain = prompt.value.replace(/<[^>]*>/g, '').trim();
        if (plain === '' && !/<img\b/i.test(prompt.value)) {
            event.preventDefault();
            window.quizMyClassApp.dialog.alert('Write the question before saving.', 'Question needed');
        }
    });
})();
