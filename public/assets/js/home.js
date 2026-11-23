(() => {
    'use strict';

    const form = document.querySelector('[data-new-room-form]');
    if (!form) {
        return;
    }

    const name = form.querySelector('[name="name"]');
    const slug = form.querySelector('[name="slug"]');
    const preview = form.querySelector('[data-slug-preview]');
    let slugEdited = false;

    const toSlug = (value) => value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);

    const showPreview = () => {
        preview.textContent = slug.value || 'your-link';
    };

    slugEdited = slug.value !== '';
    showPreview();

    name.addEventListener('input', () => {
        if (!slugEdited) {
            slug.value = toSlug(name.value);
            showPreview();
        }
    });
    slug.addEventListener('input', () => {
        slugEdited = slug.value !== '';
        showPreview();
    });
})();
