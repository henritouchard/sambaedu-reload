// ─── Rendu ────────────────────────────────────────────────────────────────────
const STATUS_ORDER = ["backlog", "ready-for-dev", "in-progress", "review", "done"];

function badge(status) {
  const labels = {
    "backlog":       "Backlog",
    "ready-for-dev": "Ready for dev",
    "in-progress":   "In progress",
    "review":        "Review",
    "done":          "Done",
    "optional":      "Rétro (optional)",
    "notes":         "Notes",
    "todo":          "Todo",
    "to-test":       "À tester",
    "paused":        "Paused",
    "deferred":      "Reporté post-prod",
    "cancelled":     "Cancelled",
    "superseded":   "Superseded",
  };
  return `<span class="badge ${status}">${labels[status] || status}</span>`;
}

function progressFor(epic) {
  const real   = epic.stories.filter(s => s.status !== "section-header");
  const total  = real.length;
  const done   = real.filter(s => s.status === "done" || s.status === "native" || s.status === "cancelled" || s.status === "superseded").length;
  const active = real.filter(s => ["in-progress","review","ready-for-dev"].includes(s.status)).length;
  return { total, done, active };
}

function computeGlobalStats(epics) {
  const totalEpics   = epics.length;
  const totalStories = epics.reduce((a, e) => a + e.stories.filter(s => s.status !== "section-header").length, 0);
  const doneStories  = epics.reduce((a, e) => a + e.stories.filter(s => s.status === "done" || s.status === "cancelled").length, 0);
  const inProgressE  = epics.filter(e => e.status === "in-progress").length;
  const readyStories = epics.reduce((a, e) => a + e.stories.filter(s => s.status === "ready-for-dev").length, 0);
  return { totalEpics, totalStories, doneStories, inProgressE, readyStories };
}

function renderStatsBar(epics, key) {
  if (key === "notes") {
    const all = epics.flatMap(e => e.stories).filter(s => s.status !== "section-header");
    const todos  = all.filter(s => s.status === "todo").length;
    const toTest = all.filter(s => s.status === "to-test").length;
    document.getElementById("statsBar").innerHTML = [
      { val: epics.length, lbl: "Sections" },
      { val: all.length,   lbl: "Entrées" },
      { val: todos,        lbl: "Todos" },
      { val: toTest,       lbl: "À tester" },
    ].map(s => `<div class="stat"><div class="val">${s.val}</div><div class="lbl">${s.lbl}</div></div>`).join("");
    return;
  }
  const { totalEpics, totalStories, doneStories, inProgressE, readyStories } = computeGlobalStats(epics);
  const pct = totalStories ? Math.round(doneStories / totalStories * 100) : 0;
  document.getElementById("statsBar").innerHTML = [
    { val: totalEpics,   lbl: "Épics" },
    { val: totalStories, lbl: "Stories" },
    { val: inProgressE,  lbl: "Épics en cours" },
    { val: readyStories, lbl: "Ready for dev" },
    { val: doneStories,  lbl: "Stories done" },
    { val: pct + "%",    lbl: "Progression globale" },
  ].map(s => `<div class="stat"><div class="val">${s.val}</div><div class="lbl">${s.lbl}</div></div>`).join("");
}

function renderEpics(epics, key) {
  const container = document.getElementById("epicsContainer");
  container.innerHTML = "";

  // Tri en 2 catégories : "pre-prod" (défaut — tout ce qui doit être livré pour passer en prod,
  // y compris Epic 1bis qui est la couche shim permettant le passage en prod) puis "post-prod"
  // (refontes natives reportées après la mise en prod car déjà couvertes par un shim).
  // Insertion d'un séparateur "passage en prod" entre les deux groupes.
  const orderedEpics = key === "notes"
    ? epics
    : [
        ...epics.filter(e => (e.category || "pre-prod") === "pre-prod"),
        ...epics.filter(e => e.category === "post-prod"),
      ];
  const hasPostProd = key !== "notes" && epics.some(e => e.category === "post-prod");
  const hasPreProd = key !== "notes" && epics.some(e => (e.category || "pre-prod") === "pre-prod");
  const postProdStartIndex = hasPostProd && hasPreProd
    ? orderedEpics.findIndex(e => e.category === "post-prod")
    : -1;

  orderedEpics.forEach((epic, idx) => {
    if (idx === postProdStartIndex) {
      const sep = document.createElement("div");
      sep.className = "category-separator";
      sep.innerHTML = `<span class="category-label">passage en prod</span><span class="category-sub">refontes natives reportées post-prod (shim de compatibilité existant couvre déjà le besoin)</span>`;
      container.appendChild(sep);
    }

    const { total, done } = progressFor(epic);
    const pct = total ? Math.round(done / total * 100) : 0;
    const fillClass = pct === 100 ? "done" : "partial";

    const card = document.createElement("div");
    // Notes tab : sections ouvertes par défaut
    const openByDefault = key === "notes" || epic.status === "in-progress";
    card.className = "epic-card" + (openByDefault ? " open" : "");

    const summaryHTML = epic.summary
      ? `<div class="epic-summary">${epic.summary}</div>`
      : "";

    const storiesHTML = epic.stories.map(s => {
      if (s.status === "section-header") {
        return `<div class="section-header-row">${s.title}</div>`;
      }
      const noteHTML = s.note ? `<div class="story-note">${s.note}</div>` : "";
      const hasTasks = Array.isArray(s.tasks) && s.tasks.length > 0;
      if (!hasTasks) {
        return `
          <div class="story-row">
            <span class="story-id">${s.id}</span>
            <div class="story-body">
              <span class="story-title">${s.title}</span>
              ${noteHTML}
            </div>
            ${badge(s.status)}
          </div>
        `;
      }
      // Story avec tasks : accordion + barre de progression
      const tasksDone = s.tasks.filter(t => t.status === "done").length;
      const tasksTotal = s.tasks.length;
      const tasksPct = tasksTotal ? Math.round(tasksDone / tasksTotal * 100) : 0;
      const tasksFillClass = tasksPct === 100 ? "" : (tasksPct === 0 ? "zero" : "partial");
      const tasksHTML = s.tasks.map(t => {
        // Marqueur shim/native : affiché quand t.via est défini (uniquement pertinent pour done)
        const viaTag = t.via
          ? `<span class="shim-tag ${t.via === "native" ? "native" : ""}">${t.via}</span>`
          : "";
        return `
          <div class="task-row">
            <span class="task-title ${t.status === "done" ? "done" : ""}">${t.title}</span>
            ${viaTag}
            ${badge(t.status)}
          </div>
        `;
      }).join("");
      return `
        <div class="story-row has-tasks" data-story-id="${s.id}">
          <span class="story-chevron">▶</span>
          <span class="story-id">${s.id}</span>
          <div class="story-body">
            <span class="story-title">${s.title}</span>
            <span class="tasks-progress">
              <span class="tasks-progress-bar"><span class="tasks-progress-fill ${tasksFillClass}" style="width:${tasksPct}%"></span></span>
              <span>${tasksDone}/${tasksTotal}</span>
            </span>
            ${noteHTML}
          </div>
          ${badge(s.status)}
        </div>
        <div class="tasks-panel">${tasksHTML}</div>
      `;
    }).join("");

    const isNotes = key === "notes";
    const headerLabel = isNotes ? epic.num : `EPIC ${epic.num}`;
    const progressHTML = isNotes ? "" : `
        <div class="epic-progress">
          <div class="progress-bar"><div class="progress-fill ${fillClass}" style="width:${pct}%"></div></div>
          <span class="progress-label">${done}/${total}</span>
        </div>`;

    card.innerHTML = `
      <div class="epic-header">
        <span class="chevron">▶</span>
        <span class="epic-num">${headerLabel}</span>
        <span class="epic-title">${epic.title}</span>
        ${progressHTML}
        ${badge(epic.status)}
      </div>
      <div class="stories">${summaryHTML}${storiesHTML}</div>
    `;

    card.querySelector(".epic-header").addEventListener("click", () => {
      card.classList.toggle("open");
    });

    // Listeners pour les stories avec tasks (toggle accordion imbriqué)
    card.querySelectorAll(".story-row.has-tasks").forEach(row => {
      row.addEventListener("click", (e) => {
        e.stopPropagation();
        row.classList.toggle("open");
      });
    });

    container.appendChild(card);
  });
}

function renderTab(key) {
  document.querySelectorAll(".tab").forEach(b => b.classList.toggle("active", b.dataset.key === key));
  const epics = DATASETS[key];
  renderStatsBar(epics, key);
  renderEpics(epics, key);
  try { localStorage.setItem("backlog.activeTab", key); } catch (_) {}
}

function renderTabs() {
  const tabsEl = document.getElementById("tabs");
  const tabsConfig = [
    { key: "sambaedu", label: "SambaEdu (SER)" },
    { key: "central",  label: "Central (controlHub / irundoo)" },
    { key: "notes",    label: "Notes & todo" },
  ];
  tabsEl.innerHTML = tabsConfig.map(t => {
    let countLabel;
    if (t.key === "notes") {
      const all = DATASETS[t.key].flatMap(e => e.stories).filter(s => s.status !== "section-header");
      countLabel = String(all.length);
    } else {
      const { totalStories, doneStories } = computeGlobalStats(DATASETS[t.key]);
      countLabel = `${doneStories}/${totalStories}`;
    }
    return `<button class="tab" data-key="${t.key}">${t.label}<span class="count">${countLabel}</span></button>`;
  }).join("");
  tabsEl.querySelectorAll(".tab").forEach(btn => {
    btn.addEventListener("click", () => renderTab(btn.dataset.key));
  });
}

// ─── Init ─────────────────────────────────────────────────────────────────────
renderTabs();
const saved = (() => { try { return localStorage.getItem("backlog.activeTab"); } catch (_) { return null; } })();
renderTab(saved && DATASETS[saved] ? saved : "sambaedu");
