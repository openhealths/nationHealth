@once
    <script>
        window.referralPrototype = function (config) {
            const copy = (value) => JSON.parse(JSON.stringify(value));
            return {
                config,
                stage: "form",
                items: [],
                editingId: null,
                formDraft: null,
                formDraftId: null,
                drafts: {},
                defaultForm: null,
                informMethod: "sms",
                showSignatureDrawer: false,
                showErrorModal: false,
                errorMessage: "",
                prototypeSaved: false,
                historyEntry(stage = this.stage) {
                    return {
                        referralPrototype: true,
                        key: config.key,
                        stage,
                        editingId: this.editingId,
                    };
                },
                restoreHistorySelection() {
                    const entry = history.state;
                    const requested =
                        entry?.referralPrototype && entry.key === config.key
                            ? entry.editingId
                            : null;
                    const id =
                        requested &&
                        this.items.some((item) => item.id === requested)
                            ? requested
                            : null;
                    if (requested && !id && this.formDraftId === requested) {
                        this.formDraft = null;
                        this.formDraftId = null;
                    }
                    this.editingId = id;
                },
                draftKey(id) {
                    return id ? "ref:" + id : "new";
                },
                draftForSelection() {
                    return (
                        this.drafts[this.draftKey(this.editingId)] ||
                        (this.formDraftId === this.editingId
                            ? this.formDraft
                            : null)
                    );
                },
                init() {
                    try {
                        const stored = JSON.parse(
                            sessionStorage.getItem(config.key) || "null",
                        );
                        if (
                            stored?.key === config.key &&
                            Array.isArray(stored.items)
                        ) {
                            this.items = stored.items;
                            this.editingId = stored.editingId || null;
                            this.formDraft = stored.formDraft || null;
                            this.formDraftId =
                                Object.prototype.hasOwnProperty.call(
                                    stored,
                                    "formDraftId",
                                )
                                    ? stored.formDraftId
                                    : (stored.editingId ?? null);
                            this.informMethod = stored.informMethod || "sms";
                            this.drafts = stored.drafts || {};
                            if (this.formDraft)
                                this.drafts[this.draftKey(this.formDraftId)] ??=
                                    this.formDraft;
                        }
                    } catch (_) {}
                    this.stage =
                        location.pathname === config.reviewUrl
                            ? "review"
                            : "form";
                    if (
                        history.state?.referralPrototype &&
                        history.state.key === config.key
                    ) {
                        this.restoreHistorySelection();
                    } else {
                        history.replaceState(
                            this.historyEntry(),
                            "",
                            location.href,
                        );
                    }
                    if (
                        new URL(location.href).searchParams.get("error") === "1"
                    )
                        this.openError(config.messages.assistantError);
                },
                formReady(form) {
                    this.defaultForm = copy(form);
                    this.defaultForm.service_id = "";
                    this.defaultForm.reason_reference = [];
                    this.defaultForm.supporting_info = [];
                    this.defaultForm.note = "";
                    this.defaultForm.patient_instruction = "";
                    if (this.stage === "form") {
                        const saved =
                            this.draftForSelection() ||
                            this.items.find(
                                (item) => item.id === this.editingId,
                            );
                        if (saved)
                            this.$nextTick(() => this.restoreForm(saved));
                    }
                },
                persist(showSaved = false, nextState = null) {
                    const state = nextState || {
                        items: this.items,
                        editingId: this.editingId,
                        formDraft: this.formDraft,
                        informMethod: this.informMethod,
                    };
                    const savedState = {
                        ...state,
                        formDraftId:
                            state.formDraft === this.formDraft
                                ? this.formDraftId
                                : state.editingId,
                    };
                    const drafts = copy(state.drafts || this.drafts);
                    if (state.formDraft)
                        drafts[this.draftKey(savedState.formDraftId)] = copy(
                            state.formDraft,
                        );
                    savedState.drafts = drafts;
                    try {
                        sessionStorage.setItem(
                            config.key,
                            JSON.stringify({ key: config.key, ...savedState }),
                        );
                        Object.assign(this, savedState);
                        this.prototypeSaved = showSaved;
                        return true;
                    } catch (_) {
                        this.openError(config.messages.storageError);
                        return false;
                    }
                },
                navigate(stage) {
                    this.stage = stage;
                    const url =
                        stage === "review"
                            ? config.reviewUrl
                            : config.createUrl;
                    if (location.pathname !== url || location.search)
                        history.pushState(this.historyEntry(), "", url);
                    else history.replaceState(this.historyEntry(), "", url);
                    window.scrollTo(0, 0);
                },
                handleHistory() {
                    this.stage =
                        location.pathname === config.reviewUrl
                            ? "review"
                            : "form";
                    this.restoreHistorySelection();
                    this.showSignatureDrawer = false;
                    this.showErrorModal = false;
                    if (this.stage === "form") {
                        this.$nextTick(() =>
                            this.restoreForm(
                                this.draftForSelection() ||
                                    this.items.find(
                                        (item) => item.id === this.editingId,
                                    ) || {
                                        form: copy(this.defaultForm || {}),
                                        service: null,
                                    },
                            ),
                        );
                    }
                },
                createPreview(payload) {
                    if (
                        !Number.isFinite(Number(payload.form.quantity)) ||
                        Number(payload.form.quantity) <= 0
                    )
                        return this.openError(config.messages.quantityError);
                    let previews = [payload];
                    if (!payload.form.service_id || !payload.service?.name) {
                        if (this.editingId)
                            return this.openError(
                                config.messages.serviceRequired,
                            );
                        const examples =
                            this.items.length
                                ? config.demoReferrals.slice(0, 1)
                                : config.demoReferrals;
                        if (!examples.length)
                            return this.openError(
                                config.messages.serviceRequired,
                            );
                        previews = examples.map((example) => ({
                            ...copy(payload),
                            service: copy(example.service),
                            form: {
                                ...copy(payload.form),
                                service_id: example.service.id,
                                category: example.service.category,
                                quantity: example.quantity,
                                quantity_code: "PIECE",
                                quantity_unit: config.pieceUnit,
                            },
                            categoryLabel: config.consultationLabel,
                            quantityUnitLabel: config.pieceUnit,
                        }));
                    }
                    const editingId = this.editingId;
                    const drafts = copy(this.drafts);
                    delete drafts[this.draftKey(editingId)];
                    const items = copy(this.items);
                    let nextEditingId = editingId;
                    for (const preview of previews) {
                        const item = copy(preview);
                        item.id =
                            previews.length === 1 && editingId
                                ? editingId
                                : crypto.randomUUID
                                  ? crypto.randomUUID()
                                  : "preview-" +
                                    Date.now() +
                                    "-" +
                                    items.length;
                        const index = items.findIndex(
                            (existing) => existing.id === item.id,
                        );
                        if (index >= 0) items.splice(index, 1, item);
                        else items.push(item);
                        nextEditingId = item.id;
                    }
                    if (
                        this.persist(false, {
                            items,
                            editingId: nextEditingId,
                            formDraft: null,
                            drafts,
                            informMethod: this.informMethod,
                        })
                    ) {
                        history.replaceState(
                            this.historyEntry("form"),
                            "",
                            location.href,
                        );
                        this.navigate("review");
                    }
                },
                saveForm(payload) {
                    this.persist(true, {
                        items: this.items,
                        editingId: this.editingId,
                        formDraft: copy(payload),
                        informMethod: this.informMethod,
                    });
                },
                restoreForm(item) {
                    window.dispatchEvent(
                        new CustomEvent("referral-prototype-restore", {
                            detail: { item: copy(item) },
                        }),
                    );
                },
                editItem(id) {
                    const item = this.items.find((item) => item.id === id);
                    if (item) this.openForm(id, item);
                },
                newItem() {
                    this.openForm(null, {
                        form: copy(this.defaultForm),
                        service: null,
                    });
                },
                openForm(id, fallback) {
                    const draft =
                        this.drafts[this.draftKey(id)] ||
                        (this.formDraftId === id ? this.formDraft : null);
                    if (
                        !this.persist(false, {
                            items: this.items,
                            editingId: id,
                            formDraft: draft,
                            informMethod: this.informMethod,
                        })
                    )
                        return;
                    this.navigate("form");
                    this.$nextTick(() => this.restoreForm(draft || fallback));
                },
                discardFormDraft() {
                    const drafts = copy(this.drafts);
                    delete drafts[this.draftKey(this.editingId)];
                    if (
                        !this.persist(false, {
                            items: this.items,
                            editingId: null,
                            formDraft: null,
                            drafts,
                            informMethod: this.informMethod,
                        })
                    )
                        return false;
                    history.replaceState(
                        this.historyEntry("form"),
                        "",
                        location.href,
                    );
                    this.restoreForm(
                        this.draftForSelection() || {
                            form: copy(this.defaultForm),
                            service: null,
                        },
                    );
                    return true;
                },
                removeItem(id) {
                    const drafts = copy(this.drafts);
                    delete drafts[this.draftKey(id)];
                    this.persist(false, {
                        drafts,
                        items: this.items.filter((item) => item.id !== id),
                        editingId:
                            this.editingId === id ? null : this.editingId,
                        formDraft:
                            this.formDraftId === id ? null : this.formDraft,
                        informMethod: this.informMethod,
                    });
                },
                discard() {
                    try {
                        sessionStorage.removeItem(config.key);
                    } catch (_) {
                        return this.openError(config.messages.storageError);
                    }
                    this.items = [];
                    this.editingId = null;
                    this.formDraft = null;
                    this.formDraftId = null;
                    this.drafts = {};
                    this.informMethod = "sms";
                    this.prototypeSaved = false;
                    this.navigate("form");
                    this.$nextTick(() =>
                        this.restoreForm({
                            form: copy(this.defaultForm),
                            service: null,
                        }),
                    );
                },
                openSignature() {
                    if (!this.items.length)
                        return this.openError(config.messages.noReferrals);
                    this.showSignatureDrawer = true;
                },
                openError(message) {
                    this.errorMessage = message;
                    this.showErrorModal = true;
                },
                returnToForm() {
                    this.showErrorModal = false;
                    if (this.stage === "review" && this.items.length)
                        this.editItem(this.editingId || this.items[0].id);
                    else if (this.stage === "review") this.newItem();
                    else this.navigate("form");
                },
            };
        };
    </script>
@endonce
