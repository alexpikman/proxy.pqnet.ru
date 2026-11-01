(() => {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const nodes = document.querySelectorAll(".stat-value, .stat-status, [data-live-value]");
    if (!nodes.length || !("MutationObserver" in window)) return;

    const animate = node => {
      node.classList.remove("is-updating");
      void node.offsetWidth;
      node.classList.add("is-updating");
    };

    const observer = new MutationObserver(records => {
      for (const record of records) {
        const node = record.target.nodeType === Node.TEXT_NODE
          ? record.target.parentElement
          : record.target;
        if (node instanceof Element && node.matches(".stat-value, .stat-status, [data-live-value]")) {
          animate(node);
        }
      }
    });

    nodes.forEach(node => observer.observe(node, {
      childList: true,
      characterData: true,
      subtree: true
    }));
  });
})();