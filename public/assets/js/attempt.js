(() => {
    'use strict';

    const app = window.quizMyClassApp;
    const form = document.querySelector('#attempt-form');
    if (!app || !form) {
        return;
    }

    const status = form.querySelector('[data-save-status]');
    const answeredCount = form.querySelector('[data-answered-count]');
    const questions = Array.from(form.querySelectorAll('[data-question]'));
    let submitting = false;
    let pendingSaves = 0;

    const setStatus = (text) => {
        status.textContent = text;
    };

    const valueFor = (card) => {
        const type = card.dataset.type;
        if (type === 'multiple_select') {
            return Array.from(card.querySelectorAll('input:checked')).map((input) => input.value);
        }
        if (type === 'multiple_choice' || type === 'true_false') {
            const checked = card.querySelector('input:checked');
            return checked ? checked.value : '';
        }
        return card.querySelector('input').value.trim();
    };

    const isAnswered = (card) => {
        const value = valueFor(card);
        return Array.isArray(value) ? value.length > 0 : value !== '';
    };

    const updateCount = () => {
        answeredCount.textContent = String(questions.filter(isAnswered).length);
    };

    // ---- Autosave one question as soon as it changes ----
    const save = (card) => {
        const body = new URLSearchParams();
        body.set('csrf_token', form.dataset.csrf);
        body.set('attempt_id', form.dataset.attemptId);
        body.set('question_id', card.dataset.question);
        const value = valueFor(card);
        if (Array.isArray(value)) {
            value.forEach((item) => body.append('answer[]', item));
        } else {
            body.set('answer', value);
        }
        pendingSaves += 1;
        setStatus('Saving…');
        fetch('/quiz/answer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: body.toString(),
        }).then((response) => response.json()).then((result) => {
            if (result.status === 'finished') {
                submitting = true;
                window.location.href = `/quiz/attempt.php?id=${encodeURIComponent(form.dataset.attemptId)}`;
                return;
            }
            setStatus(result.status === 'saved' ? 'All answers saved' : 'Not saved — they will be sent when you submit');
        }).catch(() => {
            setStatus('Offline? Answers will be sent when you submit');
        }).finally(() => {
            pendingSaves -= 1;
        });
    };

    questions.forEach((card) => {
        const textInput = card.querySelector('input[type="text"], input[type="number"]');
        card.addEventListener('change', () => {
            updateCount();
            save(card);
        });
        if (textInput) {
            // Text answers save when the field loses focus (the change event), not on every keystroke.
            textInput.addEventListener('input', updateCount);
        }
    });
    updateCount();

    // ---- Countdown ----
    const timer = form.querySelector('[data-timer]');
    if (timer) {
        const end = performance.now() + Number(form.dataset.remaining) * 1000;
        const pad = (n) => String(n).padStart(2, '0');
        const tick = () => {
            const left = Math.max(0, Math.round((end - performance.now()) / 1000));
            timer.textContent = `${pad(Math.floor(left / 60))}:${pad(left % 60)}`;
            timer.classList.toggle('timer-low', left <= 60);
            if (left === 0 && !submitting) {
                submitting = true;
                setStatus('Time is up — submitting…');
                form.submit();
                return;
            }
            window.setTimeout(tick, 500);
        };
        tick();
    }

    // ---- Submit with a gentle check for unanswered questions ----
    form.addEventListener('submit', (event) => {
        if (submitting) {
            return;
        }
        event.preventDefault();
        const unanswered = questions.length - questions.filter(isAnswered).length;
        const message = unanswered > 0
            ? `${unanswered} question${unanswered === 1 ? ' is' : 's are'} unanswered. Submit anyway?`
            : 'Submit your answers now?';
        app.dialog.confirm(message, 'Submit quiz', () => {
            submitting = true;
            form.submit();
        });
    });

    // Warn before leaving by accident; answers are saved, but the attempt would still be open.
    window.addEventListener('beforeunload', (event) => {
        if (pendingSaves > 0 && !submitting) {
            event.preventDefault();
        }
    });
})();
