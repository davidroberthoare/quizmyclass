(() => {
    'use strict';

    const app = window.quizMyClassApp;
    if (!app) {
        return;
    }

    const namedColors = new Set([
        'red', 'blue', 'green', 'orange', 'purple', 'pink', 'brown',
        'black', 'gray', 'grey', 'white', 'yellow', 'cyan', 'magenta',
    ]);

    const applyRangeStyle = (property, value) => {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            app.dialog.alert('Select some text first.', 'Text style');
            return;
        }

        const range = selection.getRangeAt(0);
        if (range.collapsed) {
            app.dialog.alert('Select some text first.', 'Text style');
            return;
        }

        const span = document.createElement('span');
        span.style.setProperty(property, value);
        span.appendChild(range.extractContents());
        range.insertNode(span);

        const nextRange = document.createRange();
        nextRange.selectNodeContents(span);
        selection.removeAllRanges();
        selection.addRange(nextRange);
        span.dispatchEvent(new Event('input', { bubbles: true }));
    };

    window.QuizMyClassRichText = {
        init(container) {
            container.querySelectorAll('[data-rich-text-editor]').forEach((element) => {
                const key = element.dataset.richTextKey;
                const source = container.querySelector(`[data-rich-text-source="${key}"]`);
                const target = container.querySelector(`[data-rich-text-target="${key}"]`);
                if (!source || !target) {
                    return;
                }

                const editor = app.textEditor.create({
                    el: element,
                    value: source.value,
                    mode: 'toolbar',
                    placeholder: key === 'prompt_html' ? 'Enter the question prompt' : 'Optional explanation',
                    clearFormattingOnPaste: true,
                    imageUrlText: 'HTTPS image URL',
                    linkUrlText: 'HTTPS link URL',
                    buttons: [
                        ['bold', 'italic', 'underline'],
                        ['orderedList', 'unorderedList'],
                        ['fontColor', 'fontSize', 'fontStyle', 'link', 'image'],
                    ],
                    customButtons: {
                        bold: {
                            content: '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15.6 10.79c.97-.68 1.6-1.75 1.6-3.04C17.2 5.03 15.19 3 12.25 3H6v18h7.04c2.88 0 4.96-1.93 4.96-4.56 0-2.02-1.15-3.76-2.4-5.65ZM8 5h4.25c1.62 0 2.95.85 2.95 2.75S13.87 10.5 12.25 10.5H8V5Zm4.96 13.5H8v-6h5.04c1.74 0 2.96 1.08 2.96 3s-1.22 3-2.96 3Z"/></svg>',
                            onClick: () => document.execCommand('bold', false),
                        },
                        italic: {
                            content: '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M10 4v3h2.21l-3.42 8H6v3h8v-3h-2.21l3.42-8H18V4h-8Z"/></svg>',
                            onClick: () => document.execCommand('italic', false),
                        },
                        underline: {
                            content: '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 17c3.31 0 6-2.69 6-6V3h-2.5v8c0 1.93-1.57 3.5-3.5 3.5S8.5 12.93 8.5 11V3H6v8c0 3.31 2.69 6 6 6ZM5 19v2h14v-2H5Z"/></svg>',
                            onClick: () => document.execCommand('underline', false),
                        },
                        orderedList: {
                            content: '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M2 17h2v.5H3v1h1v.5H2v1h3v-4H2v1Zm1-9h1V4H2v1h1v3Zm-1 3h1.8L2 13.1V14h3v-1H3.2L5 11.9V11H2v1Zm4-7v2h12V4H6Zm0 16h12v-2H6v2Zm0-6h12v-2H6v2Z"/></svg>',
                            onClick: () => document.execCommand('insertOrderedList', false),
                        },
                        unorderedList: {
                            content: '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 10.5A1.5 1.5 0 1 0 4 7.5a1.5 1.5 0 0 0 0 3Zm0 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM7 8v2h12V8H7Zm0 8h12v-2H7v2Z"/></svg>',
                            onClick: () => document.execCommand('insertUnorderedList', false),
                        },
                        fontColor: {
                            content: '<b>A</b>',
                            onClick: () => {
                                const color = window.prompt('Text color (hex or common color name):');
                                if (!color) {
                                    return;
                                }
                                const value = color.trim();
                                if (/^#[0-9a-f]{3,8}$/i.test(value) || namedColors.has(value.toLowerCase())) {
                                    applyRangeStyle('color', value);
                                } else {
                                    app.dialog.alert('Use a hex color or a common color name.', 'Text color');
                                }
                            },
                        },
                        fontSize: {
                            content: '12',
                            onClick: () => {
                                const size = window.prompt('Font size in pixels (10–72):');
                                if (!size) {
                                    return;
                                }
                                const value = Number(size);
                                if (Number.isInteger(value) && value >= 10 && value <= 72) {
                                    applyRangeStyle('font-size', value + 'px');
                                } else {
                                    app.dialog.alert('Enter a whole number from 10 to 72.', 'Font size');
                                }
                            },
                        },
                        fontStyle: {
                            content: 'Aa',
                            onClick: () => {
                                const style = window.prompt('Font style (normal, italic, or oblique):');
                                if (!style) {
                                    return;
                                }
                                const value = style.trim().toLowerCase();
                                if (['normal', 'italic', 'oblique'].includes(value)) {
                                    applyRangeStyle('font-style', value);
                                } else {
                                    app.dialog.alert('Choose normal, italic, or oblique.', 'Font style');
                                }
                            },
                        },
                        link: {
                            content: '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3.9 12a5 5 0 0 1 5-5h3v2h-3a3 3 0 0 0 0 6h3v2h-3a5 5 0 0 1-5-5Zm7-1h2.2v2H10.9Zm5.1-4h-3v2h3a3 3 0 0 1 0 6h-3v2h3a5 5 0 0 0 0-10Z"/></svg>',
                            onClick: () => {
                                const url = window.prompt('HTTPS link URL');
                                if (!url) {
                                    return;
                                }
                                const value = url.trim();
                                if (value.startsWith('https://')) {
                                    document.execCommand('createLink', false, value);
                                } else {
                                    app.dialog.alert('Use an HTTPS URL.', 'Link');
                                }
                            },
                        },
                        image: {
                            content: '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21 19V5H3v14h18ZM5 17l4.5-5.5 3.5 4 2.5-3 3.5 4.5H5Zm12-9a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/></svg>',
                            onClick: () => {
                                const url = window.prompt('HTTPS image URL');
                                if (!url) {
                                    return;
                                }
                                const value = url.trim();
                                if (value.startsWith('https://')) {
                                    document.execCommand('insertImage', false, value);
                                } else {
                                    app.dialog.alert('Use an HTTPS image URL.', 'Image');
                                }
                            },
                        },
                    },
                    on: {
                        change: (textEditor) => {
                            target.value = textEditor.getValue();
                        },
                    },
                });

                element.addEventListener('texteditor:change', () => {
                    target.value = editor.getValue();
                });
                target.value = editor.getValue();
            });
        },
    };

    window.QuizMyClassRichText.init(document);
})();
