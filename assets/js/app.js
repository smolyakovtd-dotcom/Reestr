/* assets/js/app.js */
(function () {
  const CFG = window.__APP_CFG__ || {};
  const CSRF = CFG.csrf || "";

  function qs(sel, root = document) { return root.querySelector(sel); }
  function qsa(sel, root = document) { return Array.from(root.querySelectorAll(sel)); }

  /* =========================
     MODALS
  ========================= */
  window.showModal = function (name) {
    const el = qs(`#${name}_modal`);
    if (!el) return;
    el.style.display = "flex";
  };
  window.closeModal = function (name) {
    const el = qs(`#${name}_modal`);
    if (!el) return;
    el.style.display = "none";
  };
  document.addEventListener("click", (e) => {
    const modal = e.target.closest(".modal");
    if (!modal) return;
    if (e.target === modal) {
      const backdropClose = modal.getAttribute("data-backdrop-close");
      if (backdropClose === "0") return;
      modal.style.display = "none";
    }
  });

  /* =========================
     DATE MASK (DD.MM.YY or DD.MM.YYYY)
  ========================= */
  function formatDateDigits(digits) {
    const d = digits.slice(0, 2);
    const m = digits.slice(2, 4);
    const y = digits.slice(4, 8);
    let out = "";
    if (d.length) out += d;
    if (m.length) out += "." + m;
    if (y.length) out += "." + y;
    return out;
  }
  function attachDateMask(input) {
    if (!input) return;
    if (input.dataset.masked === "1") return;
    input.dataset.masked = "1";

    input.addEventListener("input", () => {
      const old = input.value;
      const digits = old.replace(/\D/g, "");
      const formatted = formatDateDigits(digits);
      if (formatted !== old) {
        const start = input.selectionStart || 0;
        const before = old.slice(0, start).replace(/\D/g, "");
        const newPos = formatDateDigits(before).length;
        input.value = formatted;
        try { input.setSelectionRange(newPos, newPos); } catch (_) {}
      }
    });

    input.addEventListener("blur", () => {
      const v = input.value.trim();
      if (!v) { input.classList.remove("invalid"); return; }
      if (!/^\d{2}\.\d{2}\.\d{2}(\d{2})?$/.test(v)) input.classList.add("invalid");
      else input.classList.remove("invalid");
    });
  }
  function initDateMasks() {
    ["#doc_date", "#select_date", "#print_from_date", "#print_to_date", "#from_date", "#to_date"]
      .forEach((s) => attachDateMask(qs(s)));
  }

  /* =========================
     FREEZE: одиночные поля + ПАРА contractor/inn
  ========================= */
  const frozen = {};         // id -> boolean
  let pairFrozen = false;    // contractor+inn frozen
  const PAIR_IDS = ["contractor", "inn"];

  function setFrozenStyle(id, isFrozen) {
    const inp = qs(`#${id}`);
    if (!inp) return;
    if (isFrozen) inp.classList.add("frozen");
    else inp.classList.remove("frozen");
  }
  function setInputReadOnly(id, isReadOnly) {
    const inp = qs(`#${id}`);
    if (!inp) return;
    inp.readOnly = !!isReadOnly;
    inp.setAttribute("aria-readonly", isReadOnly ? "true" : "false");
  }
  function setFreezeBtnActive(btnId, active) {
    const btn = qs(`#${btnId}`);
    if (!btn) return;
    if (active) btn.classList.add("active");
    else btn.classList.remove("active");
  }

  window.toggleFreeze = function (id) {
    frozen[id] = !frozen[id];
    setFrozenStyle(id, frozen[id]);
    setInputReadOnly(id, frozen[id]);
    setFreezeBtnActive("freeze_" + id, frozen[id]);

    try {
      localStorage.setItem("frozen_" + id, frozen[id] ? "1" : "0");
      if (frozen[id]) localStorage.setItem("frozen_val_" + id, qs("#" + id)?.value ?? "");
    } catch (_) {}
  };

  window.toggleFreezePair = function () {
    pairFrozen = !pairFrozen;

    PAIR_IDS.forEach((id) => {
      setFrozenStyle(id, pairFrozen);
      setInputReadOnly(id, pairFrozen);
    });
    setFreezeBtnActive("freeze_pair", pairFrozen);
    setFreezeBtnActive("freeze_inn", pairFrozen);

    try {
      localStorage.setItem("pair_frozen", pairFrozen ? "1" : "0");
      if (pairFrozen) {
        localStorage.setItem("pair_contractor", qs("#contractor")?.value ?? "");
        localStorage.setItem("pair_inn", qs("#inn")?.value ?? "");
      }
    } catch (_) {}
  };

  function restoreFrozen() {
    ["doc_date", "doc_name"].forEach((id) => {
      let st = "0", val = "";
      try {
        st = localStorage.getItem("frozen_" + id) || "0";
        val = localStorage.getItem("frozen_val_" + id) || "";
      } catch (_) {}
      frozen[id] = st === "1";
      if (frozen[id]) {
        const inp = qs("#" + id);
        if (inp && val) inp.value = val;
      }
      setFrozenStyle(id, frozen[id]);
      setInputReadOnly(id, frozen[id]);
      setFreezeBtnActive("freeze_" + id, frozen[id]);
    });

    let pst = "0", pName = "", pInn = "";
    try {
      pst = localStorage.getItem("pair_frozen") || "0";
      pName = localStorage.getItem("pair_contractor") || "";
      pInn = localStorage.getItem("pair_inn") || "";
    } catch (_) {}
    pairFrozen = pst === "1";
    if (pairFrozen) {
      const c = qs("#contractor"); const i = qs("#inn");
      if (c && pName) c.value = pName;
      if (i && pInn) i.value = pInn;
    }
    PAIR_IDS.forEach((id) => {
      setFrozenStyle(id, pairFrozen);
      setInputReadOnly(id, pairFrozen);
    });
    setFreezeBtnActive("freeze_pair", pairFrozen);
    setFreezeBtnActive("freeze_inn", pairFrozen);
  }

  /* =========================
     contractor <-> inn (PAIR)
  ========================= */
  function findContractorOptionByName(name) {
    const list = qs("#contractors_list");
    if (!list) return null;
    return Array.from(list.options).find((o) => o.value === name) || null;
  }
  function findContractorOptionByInn(inn) {
    const list = qs("#contractors_list");
    if (!list) return null;
    const digits = String(inn || "").replace(/\D/g, "");
    return Array.from(list.options).find((o) => (o.getAttribute("data-inn") || "") === digits) || null;
  }

  function bindPairInputs(contractorInput, innInput) {
    if (!contractorInput || !innInput) return;

    contractorInput.addEventListener("input", () => {
      if (pairFrozen) return;

      const name = contractorInput.value.trim();
      if (name === "") { innInput.value = ""; return; }
      const opt = findContractorOptionByName(name);
      if (!opt) {
        innInput.value = "";
        return;
      }
      const expectedInn = opt.getAttribute("data-inn") || "";
      if (innInput.value.replace(/\D/g, "") && innInput.value.replace(/\D/g, "") !== expectedInn) {
        innInput.value = "";
        return;
      }
      innInput.value = expectedInn;
    });

    innInput.addEventListener("input", () => {
      if (pairFrozen) return;

      const innDigits = innInput.value.replace(/\D/g, "");
      if (innInput.value !== innDigits) innInput.value = innDigits;

      if (innDigits === "") { contractorInput.value = ""; return; }
      const opt = findContractorOptionByInn(innDigits);
      if (!opt) {
        contractorInput.value = "";
        return;
      }
      const expectedName = opt.value || "";
      if (contractorInput.value.trim() && contractorInput.value.trim() !== expectedName) {
        contractorInput.value = "";
        return;
      }
      contractorInput.value = expectedName;
    });

    function savePairToStorage() {
      if (!pairFrozen) return;
      try {
        localStorage.setItem("pair_contractor", contractorInput.value ?? "");
        localStorage.setItem("pair_inn", innInput.value ?? "");
      } catch (_) {}
    }
    contractorInput.addEventListener("change", savePairToStorage);
    innInput.addEventListener("change", savePairToStorage);
  }

  /* =========================
     TABS
  ========================= */
  function setType(type) {
    const typeInput = qs("#type_input");
    if (typeInput) typeInput.value = type;

    const tk = qs("#tk_num");
    if (tk) tk.style.display = String(type).includes("_tk") ? "" : "none";
  }
  function initTabs() {
    const tabs = qsa(".tabs .tab");
    if (!tabs.length) return;

    function activate(tab) {
      tabs.forEach((t) => t.classList.remove("active"));
      tab.classList.add("active");
      setType(tab.getAttribute("data-type") || "incoming");
    }
    tabs.forEach((tab) => tab.addEventListener("click", () => activate(tab)));
    const active = tabs.find((t) => t.classList.contains("active")) || tabs[0];
    if (active) activate(active);
  }

  /* =========================
     ADD DOCUMENT (AJAX)
  ========================= */
  window.handleAddSubmit = async function (e) {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);

    try {
      const res = await fetch(location.href, { method: "POST", body: fd });
      const data = await res.json();
      if (!data || !data.success) {
        alert((data && data.message) ? data.message : "Ошибка добавления");
        return;
      }
      location.reload();
    } catch (err) {
      alert("Ошибка запроса: " + (err?.message || err));
    }
  };

  /* =========================
     DELETE DOC
  ========================= */
  window.deleteDoc = function (id) {
    if (!confirm("Удалить запись?")) return;
    const fd = new FormData();
    fd.set("csrf_token", CSRF);
    fd.set("action", "delete_document");
    fd.set("id", String(id));
    fetch(location.href, { method: "POST", body: fd })
      .then(() => location.reload())
      .catch((e) => alert("Ошибка удаления: " + (e?.message || e)));
  };

  /* =========================
     SCANS (upload/delete)
  ========================= */
  window.uploadScan = async function (e, docId) {
    e.preventDefault();
    if (!CFG.canUploadScans) return;

    const form = e.target;
    const fileInput = form.querySelector('input[type="file"][name="scan_file"]');
    const file = fileInput?.files?.[0];
    if (!file) {
      alert("Select a file");
      return;
    }

    const fd = new FormData();
    fd.set("csrf_token", CSRF);
    fd.set("action", "upload_scan");
    fd.set("doc_id", String(docId));
    fd.set("scan_file", file);

    try {
      const res = await fetch(location.href, { method: "POST", body: fd });
      const data = await res.json();
      if (!data || !data.success) {
        alert((data && data.message) ? data.message : "Upload error");
        return;
      }
      location.reload();
    } catch (err) {
      alert("Request error: " + (err?.message || err));
    }
  };

  window.deleteScan = async function (scanId) {
    if (!CFG.canUploadScans) return;
    if (!confirm("Delete this scan?")) return;

    const fd = new FormData();
    fd.set("csrf_token", CSRF);
    fd.set("action", "delete_scan");
    fd.set("scan_id", String(scanId));

    try {
      const res = await fetch(location.href, { method: "POST", body: fd });
      const data = await res.json();
      if (!data || !data.success) {
        alert((data && data.message) ? data.message : "Delete error");
        return;
      }
      location.reload();
    } catch (err) {
      alert("Request error: " + (err?.message || err));
    }
  };

  /* =========================
     EDIT DOC (inline)
  ========================= */
  function makeInput(value, cls, placeholder = "") {
    const i = document.createElement("input");
    i.type = "text";
    i.value = value || "";
    i.className = cls || "";
    if (placeholder) i.placeholder = placeholder;
    return i;
  }
  function makeDatalistInput(value, listId, cls) {
    const i = makeInput(value, cls);
    i.setAttribute("list", listId);
    return i;
  }
  function bindPairInputsRow(contractorInput, innInput) {
    function findByName(name) {
      const list = qs("#contractors_list");
      if (!list) return null;
      return Array.from(list.options).find((o) => o.value === name) || null;
    }
    function findByInn(inn) {
      const list = qs("#contractors_list");
      if (!list) return null;
      const digits = String(inn || "").replace(/\D/g, "");
      return Array.from(list.options).find((o) => (o.getAttribute("data-inn") || "") === digits) || null;
    }

    contractorInput.addEventListener("input", () => {
      const name = contractorInput.value.trim();
      if (name === "") { innInput.value = ""; return; }
      const opt = findByName(name);
      if (!opt) {
        innInput.value = "";
        return;
      }
      const expectedInn = opt.getAttribute("data-inn") || "";
      if (innInput.value.replace(/\D/g, "") && innInput.value.replace(/\D/g, "") !== expectedInn) {
        innInput.value = "";
        return;
      }
      innInput.value = expectedInn;
    });

    innInput.addEventListener("input", () => {
      const digits = innInput.value.replace(/\D/g, "");
      if (innInput.value !== digits) innInput.value = digits;

      if (digits === "") { contractorInput.value = ""; return; }
      const opt = findByInn(digits);
      if (!opt) {
        contractorInput.value = "";
        return;
      }
      const expectedName = opt.value || "";
      if (contractorInput.value.trim() && contractorInput.value.trim() !== expectedName) {
        contractorInput.value = "";
        return;
      }
      contractorInput.value = expectedName;
    });
  }

  window.startDocEdit = function (btn) {
    const tr = btn.closest("tr.doc-row");
    if (!tr) return;
    if (tr.dataset.editing === "1") return;
    tr.dataset.editing = "1";

    const tds = tr.querySelectorAll("td.cell-view");
    const type = tr.dataset.type || "";

    const values = Array.from(tds).map((td) => td.textContent.trim());

    const contractor = values[0] || "";
    const inn = values[1] || "";
    const docName = values[2] || "";
    const docNum = values[3] || "";
    const docDate = values[4] || "";
    const tkNum = (String(type).includes("_tk") ? (values[5] || "") : "");

    tds[0].innerHTML = "";
    const cInput = makeDatalistInput(contractor, "contractors_list", "edit-input contractor-edit");
    tds[0].appendChild(cInput);

    tds[1].innerHTML = "";
    const iInput = makeInput(inn, "edit-input inn-edit");
    iInput.maxLength = 12;
    tds[1].appendChild(iInput);

    bindPairInputsRow(cInput, iInput);

    tds[2].innerHTML = "";
    const dnInput = makeDatalistInput(docName, "doc_types_list", "edit-input");
    tds[2].appendChild(dnInput);

    tds[3].innerHTML = "";
    tds[3].appendChild(makeInput(docNum, "edit-input"));

    tds[4].innerHTML = "";
    const dd = makeInput(docDate, "edit-input date-edit", "ДД.ММ.ГГ");
    tds[4].appendChild(dd);
    attachDateMask(dd);

    if (String(type).includes("_tk")) {
      const tkTd = tds[5];
      tkTd.innerHTML = "";
      tkTd.appendChild(makeInput(tkNum, "edit-input"));
    }

    toggleDocButtons(tr, true);
  };

  window.cancelDocEdit = function () {
    location.reload();
  };

  window.saveDocEdit = async function (btn) {
    const tr = btn.closest("tr.doc-row");
    if (!tr) return;

    const id = tr.dataset.id;
    const tds = tr.querySelectorAll("td.cell-view");
    const type = tr.dataset.type || "";

    const cInput = tds[0].querySelector("input");
    const iInput = tds[1].querySelector("input");
    const dnInput = tds[2].querySelector("input");
    const numInput = tds[3].querySelector("input");
    const dateInput = tds[4].querySelector("input");

    const payload = {
      id: id,
      contractor: (cInput?.value || "").trim(),
      inn: (iInput?.value || "").trim(),
      doc_name: (dnInput?.value || "").trim(),
      doc_num: (numInput?.value || "").trim(),
      doc_date: (dateInput?.value || "").trim(),
      tk_num: ""
    };

    if (String(type).includes("_tk")) {
      const tkInput = tds[5].querySelector("input");
      payload.tk_num = (tkInput?.value || "").trim();
    }

    const fd = new FormData();
    fd.set("csrf_token", CSRF);
    fd.set("action", "update_document");
    Object.entries(payload).forEach(([k, v]) => fd.set(k, String(v)));

    try {
      const res = await fetch(location.href, { method: "POST", body: fd });
      const data = await res.json();
      if (!data || !data.success) {
        alert((data && data.message) ? data.message : "Ошибка сохранения");
        return;
      }
      location.reload();
    } catch (err) {
      alert("Ошибка запроса: " + (err?.message || err));
    }
  };

  function toggleDocButtons(tr, editing) {
    const bEdit = tr.querySelector(".btn-small:not(.btn-danger):not(.btn-save):not(.btn-cancel)");
    const bDel = tr.querySelector(".btn-danger");
    const bSave = tr.querySelector(".btn-save");
    const bCancel = tr.querySelector(".btn-cancel");

    if (bEdit) bEdit.style.display = editing ? "none" : "inline-block";
    if (bDel) bDel.style.display = editing ? "none" : "inline-block";
    if (bSave) bSave.style.display = editing ? "inline-block" : "none";
    if (bCancel) bCancel.style.display = editing ? "inline-block" : "none";
  }

  /* =========================
     COMMENTS autosave (всегда)
  ========================= */
  function setStatus(textarea, text) {
    const box = textarea.parentElement?.querySelector(".comment-status");
    if (!box) return;
    box.textContent = text || "";
    if (text) {
      box.classList.add("show");
      clearTimeout(box._t);
      box._t = setTimeout(() => { box.classList.remove("show"); box.textContent = ""; }, 1200);
    }
  }

  async function saveComment(textarea) {
    const id = textarea.dataset.id;
    const field = textarea.dataset.field;
    const value = textarea.value || "";

    const fd = new FormData();
    fd.set("csrf_token", CSRF);
    fd.set("action", "update_comment");
    fd.set("id", String(id));
    fd.set("field", String(field));
    fd.set("value", value);

    try {
      const res = await fetch(location.href, { method: "POST", body: fd });
      const data = await res.json();

      if (!data || !data.success) {
        setStatus(textarea, "Ошибка");
        return;
      }
      if (typeof data.value === "string" && data.value !== textarea.value) textarea.value = data.value;
      setStatus(textarea, "Сохранено");
    } catch (_) {
      setStatus(textarea, "Ошибка");
    }
  }

  function initComments() {
    if (!CFG.canManageRegistry) return;
    const areas = qsa("textarea.comment-textarea");
    areas.forEach((ta) => {
      // debounce save
      ta.addEventListener("input", () => {
        clearTimeout(ta._deb);
        ta._deb = setTimeout(() => saveComment(ta), 700);
      });
      // on blur save
      ta.addEventListener("blur", () => saveComment(ta));
    });
  }

  /* =========================
     PRINT / OPEN DAY / SEARCH
  ========================= */
  function convertInputDateToYMD(v) {
    v = String(v || "").trim();
    if (!v) return null;
    if (/^\d{2}\.\d{2}\.\d{2}$/.test(v)) {
      const [d, m, y] = v.split(".");
      return `20${y}-${m}-${d}`;
    }
    if (/^\d{2}\.\d{2}\.\d{4}$/.test(v)) {
      const [d, m, y] = v.split(".");
      return `${y}-${m}-${d}`;
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
    return null;
  }
  function ymdToDMYShort(ymd) {
    const d = new Date(ymd + "T00:00:00");
    if (Number.isNaN(d.getTime())) return "";
    const dd = String(d.getDate()).padStart(2, "0");
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const yy = String(d.getFullYear()).slice(-2);
    return `${dd}.${mm}.${yy}`;
  }
  function postPrint(from, to) {
    const f = document.createElement("form");
    f.method = "POST";
    f.action = location.href;

    const add = (name, val) => {
      const i = document.createElement("input");
      i.type = "hidden";
      i.name = name;
      i.value = val;
      f.appendChild(i);
    };

    add("csrf_token", CSRF);
    add("print_mode", "1");
    add("from", ymdToDMYShort(from));
    add("to", ymdToDMYShort(to));

    document.body.appendChild(f);
    f.submit();
  }

  window.printToday = function () {
    const d = CFG.currentDate || "";
    if (!d) return alert("Не удалось определить дату");
    postPrint(d, d);
  };
  window.printRange = function () {
    const fromRaw = (qs("#print_from_date")?.value || "").trim();
    const toRaw = (qs("#print_to_date")?.value || "").trim();
    const from = convertInputDateToYMD(fromRaw);
    const to = convertInputDateToYMD(toRaw);
    if (!from || !to) return alert("Неверный формат дат (ДД.ММ.ГГ или ДД.ММ.ГГГГ)");
    postPrint(from, to);
  };
  window.openDate = function () {
    const v = (qs("#select_date")?.value || "").trim();
    const ymd = convertInputDateToYMD(v);
    if (!ymd) return alert("Неверный формат даты (ДД.ММ.ГГ или ДД.ММ.ГГГГ)");
    location.href = `?date=${encodeURIComponent(ymd)}`;
  };
  window.performSearch = function () {
    const contractor = (qs("#search_contractor")?.value || "").trim();
    const from = (qs("#from_date")?.value || "").trim();
    const to = (qs("#to_date")?.value || "").trim();

    const f = document.createElement("form");
    f.method = "POST";
    f.action = location.href;

    const add = (name, val) => {
      const i = document.createElement("input");
      i.type = "hidden";
      i.name = name;
      i.value = val;
      f.appendChild(i);
    };

    add("csrf_token", CSRF);
    add("action", "search");
    add("contractor", contractor);
    add("from", from);
    add("to", to);

    document.body.appendChild(f);
    f.submit();
  };

  /* =========================
     REESTRS MODAL (unchanged)
  ========================= */
  window.filterReestrs = function () {
    const q = (qs("#reestr_search_input")?.value || "").toLowerCase().trim();
    const rows = qsa("#reestrs_table tbody tr");
    rows.forEach((tr) => {
      const txt = tr.innerText.toLowerCase();
      tr.style.display = txt.includes(q) ? "" : "none";
    });
  };
  window.repeatPrint = function (minDate, maxDate) {
    if (!minDate || !maxDate) return;
    postPrint(minDate, maxDate);
  };

  let selectedReestrNumber = 0;
  window.openEmailForReestr = function (num) {
    selectedReestrNumber = Number(num) || 0;
    showModal("email");
  };
  window.sendSelectedReestrEmail = async function () {
    const email = (qs("#email_address")?.value || "").trim();
    if (!selectedReestrNumber) return alert("Не выбран реестр");
    if (!email) return alert("РЈРєР°Р¶РёС‚Рµ e-mail");

    const fd = new FormData();
    fd.set("csrf_token", CSRF);
    fd.set("action", "send_reestr_email");
    fd.set("registry_number", String(selectedReestrNumber));
    fd.set("email", email);

    try {
      const res = await fetch(location.href, { method: "POST", body: fd });
      const data = await res.json();
      if (!data || !data.success) {
        alert((data && data.message) ? data.message : "Ошибка отправки");
        return;
      }
      alert("Письмо отправлено!");
      closeModal("email");
    } catch (err) {
      alert("Ошибка запроса: " + (err?.message || err));
    }
  };

  window.backup = function () {
    alert("Для бэкапа используйте phpMyAdmin или mysqldump");
  };

  window.startRowEdit = function (btn) {
    const tr = btn.closest("tr");
    if (!tr) return;
    qsa("input.row-input", tr).forEach((inp) => inp.disabled = false);
    btn.style.display = "none";
    const saveForm = qs("form.row-save-form", tr);
    if (saveForm) saveForm.style.display = "inline";
  };
  window.cancelRowEdit = function () { location.reload(); };

  document.addEventListener("submit", (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.classList.contains("row-save-form")) return;

    const tr = form.closest("tr");
    if (!tr) return;

    const hiddenName = qs('input[type="hidden"][name="name"]', form);
    const hiddenInn = qs('input[type="hidden"][name="inn"]', form);
    const nameInput = qs('input.row-input[name="name"]', tr);
    const innInput = qs('input.row-input[name="inn"]', tr);

    if (hiddenName && nameInput) hiddenName.value = nameInput.value;
    if (hiddenInn && innInput) hiddenInn.value = innInput.value;
  });

  /* =========================
     INIT
  ========================= */
  function init() {
    restoreFrozen();
    initTabs();
    initDateMasks();
    bindPairInputs(qs("#contractor"), qs("#inn"));
    initComments();

    // ✅ FIX: после поиска модалка сама открывается
    if (CFG.searchPerformed) {
      showModal("search");
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();
