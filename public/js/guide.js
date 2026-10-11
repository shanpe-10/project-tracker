/* Searchable handbook. Each role keeps its own topics and reading position. */
(() => {
    const guides = new Map();
    function activeGuide() {
        return [...guides.values()].find(guide => !guide.root.classList.contains('d-none'));
    }
    function updateFooter(guide) {
        if (guide !== activeGuide()) return;
        const index = guide.visible.indexOf(guide.current);
        const previous = document.getElementById('guidePreviousButton');
        const next = document.getElementById('guideNextButton');
        previous.disabled = index <= 0;
        next.disabled = index < 0 || index >= guide.visible.length - 1;
        document.getElementById('guideTopicProgress').textContent = index < 0 ? 'No matching topics' : `Topic ${index + 1} of ${guide.visible.length}`;
    }
    function select(guide, id, focus = false) {
        guide.current = guide.visible.includes(id) ? id : guide.visible[0];
        guide.topics.forEach(topic => {
            const selected = topic.id === guide.current;
            topic.panel.hidden = !selected;
            topic.button.setAttribute('aria-current', String(selected));
        });
        guide.select.value = guide.current || '';
        if (guide.intro) guide.intro.hidden = guide.current !== guide.topics[0]?.id;
        guide.empty.hidden = Boolean(guide.current);
        guide.reading.scrollTop = 0;
        if (focus && guide.current) guide.topics.find(topic => topic.id === guide.current).panel.focus({ preventScroll: true });
        updateFooter(guide);
    }
    function filter(guide) {
        const words = guide.search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
        guide.visible = guide.topics.filter(topic => {
            const matches = words.every(word => topic.text.includes(word));
            topic.button.hidden = !matches;
            topic.option.hidden = !matches;
            topic.option.disabled = !matches;
            return matches;
        }).map(topic => topic.id);
        guide.select.disabled = !guide.visible.length;
        select(guide, guide.current);
    }
    function initialize() {
        document.querySelectorAll('[data-tracker-guide]').forEach(root => {
            const guide = { root, search: root.querySelector('[data-guide-search]'), select: root.querySelector('[data-guide-select]'), reading: root.querySelector('.guide-reading-pane'), empty: root.querySelector('.guide-empty'), intro: root.querySelector('.guide-intro'), current: null, visible: [], topics: [] };
            root.querySelectorAll('[data-guide-topic]').forEach(panel => {
                const button = root.querySelector(`[aria-controls="${panel.id}"]`);
                const option = [...guide.select.options].find(item => item.value === panel.id);
                guide.topics.push({ id: panel.id, panel, button, option, text: `${button.textContent} ${panel.textContent}`.toLowerCase().replace(/\s+/g, ' ') });
                button.addEventListener('click', () => select(guide, panel.id, true));
            });
            guides.set(root.id, guide);
            guide.search.addEventListener('input', () => filter(guide));
            guide.select.addEventListener('change', () => select(guide, guide.select.value));
            filter(guide);
        });
        document.getElementById('systemGuideModal')?.addEventListener('show.bs.modal', () => {
            const guide = activeGuide();
            if (guide) updateFooter(guide);
        });
        for (const [id, delta] of [['guidePreviousButton', -1], ['guideNextButton', 1]]) {
            document.getElementById(id)?.addEventListener('click', () => {
                const guide = activeGuide();
                if (!guide) return;
                const index = guide.visible.indexOf(guide.current) + delta;
                if (index >= 0 && index < guide.visible.length) select(guide, guide.visible[index], true);
            });
        }
    }
    document.addEventListener('DOMContentLoaded', initialize);
})();
