(() => {
  const modal = document.querySelector(".ppcfw-modal");
  const openButtons = document.querySelectorAll(".ppcfw-open-modal");
  const closeButtons = document.querySelectorAll(".ppcfw-close-modal");
  const stepBack = document.querySelector(".ppcfw-step-back");
  const stepNext = document.querySelector(".ppcfw-step-next");
  const submitButton = document.querySelector(".ppcfw-submit-catalog");
  const steps = Array.from(document.querySelectorAll(".ppcfw-wizard-step"));
  const clientNameInput = document.getElementById("ppcfw-client-name");
  const typeInputs = Array.from(document.querySelectorAll('input[name="catalog_type"]'));
  const pendingRecordIds = Array.isArray(window.ppcfwAdmin?.pendingRecordIds) ? window.ppcfwAdmin.pendingRecordIds : [];
  let currentStep = 1;

  const openModal = () => {
    if (!modal) {
      return;
    }
    modal.hidden = false;
    document.body.classList.add("ppcfw-modal-open");
    renderStep();
  };

  const closeModal = () => {
    if (!modal) {
      return;
    }
    modal.hidden = true;
    document.body.classList.remove("ppcfw-modal-open");
  };

  const isClientSpecific = () => {
    const selected = typeInputs.find((input) => input.checked);
    return selected?.value === "client-specific";
  };

  const renderStep = () => {
    steps.forEach((step) => {
      step.classList.toggle("is-active", Number(step.dataset.step) === currentStep);
    });

    if (stepBack) {
      stepBack.disabled = currentStep === 1;
    }

    if (stepNext) {
      stepNext.hidden = currentStep === steps.length;
    }

    if (submitButton) {
      submitButton.hidden = currentStep !== steps.length;
    }
  };

  const validateCurrentStep = () => {
    if (currentStep === 2 && isClientSpecific() && clientNameInput && clientNameInput.value.trim() === "") {
      window.alert(window.ppcfwAdmin?.strings?.clientNameRequired || "Client name is required.");
      clientNameInput.focus();
      return false;
    }

    return true;
  };

  openButtons.forEach((button) => {
    button.addEventListener("click", openModal);
  });

  closeButtons.forEach((button) => {
    button.addEventListener("click", closeModal);
  });

  if (stepBack) {
    stepBack.addEventListener("click", () => {
      currentStep = Math.max(1, currentStep - 1);
      renderStep();
    });
  }

  if (stepNext) {
    stepNext.addEventListener("click", () => {
      if (!validateCurrentStep()) {
        return;
      }

      currentStep = Math.min(steps.length, currentStep + 1);
      renderStep();
    });
  }

  typeInputs.forEach((input) => {
    input.addEventListener("change", () => {
      if (!isClientSpecific() && clientNameInput) {
        clientNameInput.value = "";
      }
    });
  });

  const updateRecordRow = (record) => {
    const row = document.querySelector(`[data-record-id="${record.id}"]`);
    if (!row) {
      return;
    }

    row.dataset.recordStatus = record.status;

    const statusCell = row.querySelector(".ppcfw-status-cell");
    if (statusCell) {
      const status = statusCell.querySelector(".ppcfw-status");
      if (status) {
        status.textContent = record.statusLabel;
        status.className = `ppcfw-status ppcfw-status--${record.status}`;
      }

      const description = statusCell.querySelector(".description");
      if (description && record.errorMessage) {
        description.textContent = record.errorMessage;
      }
    }

    const productCount = row.querySelector(".ppcfw-product-count");
    if (productCount) {
      productCount.textContent = String(record.productCount ?? 0);
    }

    const actionsCell = row.querySelector(".ppcfw-actions-cell");
    if (actionsCell && record.downloadUrl) {
      actionsCell.innerHTML = `<a class="button button-secondary" href="${record.downloadUrl}">Download PDF</a>`;
    }
  };

  const pollStatuses = async () => {
    const recordIds = Array.from(
      document.querySelectorAll('[data-record-status="queued"], [data-record-status="processing"]')
    ).map((row) => Number(row.getAttribute("data-record-id"))).filter(Boolean);

    if (recordIds.length === 0) {
      return;
    }

    const body = new URLSearchParams();
    body.set("action", "ppcfw_catalog_statuses");
    body.set("nonce", window.ppcfwAdmin?.statusNonce || "");
    recordIds.forEach((id) => body.append("record_ids[]", String(id)));

    const response = await fetch(window.ppcfwAdmin?.ajaxUrl || "", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: body.toString(),
    });

    if (!response.ok) {
      return;
    }

    const payload = await response.json();
    if (!payload?.success || !Array.isArray(payload?.data?.records)) {
      return;
    }

    payload.data.records.forEach(updateRecordRow);
  };

  if (pendingRecordIds.length > 0) {
    window.setInterval(() => {
      pollStatuses().catch(() => {});
    }, 5000);
  }

  renderStep();
})();
