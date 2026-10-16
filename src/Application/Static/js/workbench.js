/**
 * Semitexa Platform UI — the Workbench index search.
 *
 * Narrows the catalog in the sidebar as you type: an entry stays when every
 * word typed is in its short name, its full name or its summary (the
 * `data-workbench-text` the server writes). A group with nothing left is
 * hidden with its heading, the count is announced in a polite live region,
 * and Escape clears the box. The box is server-rendered `hidden` and shown
 * here, so a page without this script has no control that does nothing.
 */
const root = document.querySelector('[data-workbench-search]');
const nav = root ? root.closest('.wb-nav') : null;

if (root && nav) {
    const input = root.querySelector('input[type="search"]');
    const status = root.querySelector('[data-workbench-search-status]');
    const items = Array.from(nav.querySelectorAll('[data-workbench-link]')).map((link) => ({
        li: link.closest('li') || link,
        text: link.getAttribute('data-workbench-text') || '',
    }));
    const groups = Array.from(nav.querySelectorAll('.wb-group'));

    const apply = () => {
        const words = input.value.toLowerCase().split(/\s+/).filter((w) => w !== '');
        let shown = 0;
        for (const item of items) {
            const match = words.every((w) => item.text.includes(w));
            item.li.hidden = !match;
            if (match) shown++;
        }
        for (const group of groups) {
            group.hidden = !Array.from(group.querySelectorAll('[data-workbench-link]')).some((a) => !(a.closest('li') || a).hidden);
        }
        if (words.length === 0) {
            status.textContent = items.length + ' entries';
        } else {
            status.textContent = shown === 0 ? 'No entries match' : shown + ' of ' + items.length + ' entries';
        }
    };

    input.addEventListener('input', apply);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && input.value !== '') {
            e.preventDefault();
            input.value = '';
            apply();
        }
    });
    root.hidden = false;
    apply();
}
