(() => {
  document.addEventListener('DOMContentLoaded', () => {
    console.log('LMessage管理ツール: app.js loaded');

    // 例: 軽いクリック演出
    document.querySelectorAll('.btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        btn.animate(
          [
            { transform: 'scale(1)' },
            { transform: 'scale(0.98)' },
            { transform: 'scale(1)' }
          ],
          { duration: 180, easing: 'ease-out' }
        );
      });
    });
    const fileInput = document.getElementById('csv-file');
    const dropZone = document.getElementById('drop-zone');
    const selectedFile = document.getElementById('selected-file');

    if (fileInput && dropZone && selectedFile) {
      const updateFileName = () => {
        const file = fileInput.files && fileInput.files[0];
        selectedFile.textContent = file ? `選択中: ${file.name}` : '未選択';
      };

      ['dragenter', 'dragover'].forEach((eventName) => {
        dropZone.addEventListener(eventName, (event) => {
          event.preventDefault();
          dropZone.classList.add('drag-over');
        });
      });

      ['dragleave', 'drop'].forEach((eventName) => {
        dropZone.addEventListener(eventName, (event) => {
          event.preventDefault();
          dropZone.classList.remove('drag-over');
        });
      });

      dropZone.addEventListener('drop', (event) => {
        const files = event.dataTransfer?.files;
        if (!files || files.length === 0) {
          return;
        }

        fileInput.files = files;
        updateFileName();
      });

      fileInput.addEventListener('change', updateFileName);
    }

    const chatModal = document.getElementById('chat-confirm-modal');
    const messageElement = document.getElementById('chat-modal-message');
    const statusElement = document.getElementById('chat-modal-status');
    const warningIcon = document.getElementById('chat-warning-icon');
    const okButton = document.getElementById('chat-modal-ok');
    const cancelButton = chatModal?.querySelector('[data-chat-modal-cancel]');
    const backdrop = chatModal?.querySelector('[data-chat-modal-close]');
    const filterForm = document.querySelector('.aori-filter-form');
    let resultsContainer = document.getElementById('aori-results');
    const parser = new DOMParser();
    let currentModalAction = null;
    let currentContentId = null;

    const aoriLabelModal = document.getElementById('aori-label-modal');
    const aoriLabelForm = document.getElementById('aori-label-form');
    const aoriLabelSaveButton = document.getElementById('aori-label-save');
    const aoriLabelStatus = document.getElementById('aori-label-modal-status');
    const aoriLabelCancelButton = aoriLabelModal?.querySelector('[data-aori-modal-cancel]');
    const aoriLabelBackdrop = aoriLabelModal?.querySelector('[data-aori-modal-close]');
    let currentLabelLineUserId = null;
    let currentLabelMode = 'aori';

    const hideModal = () => {
      if (!chatModal) {
        return;
      }
      chatModal.hidden = true;
      currentModalAction = null;
      currentContentId = null;
      if (cancelButton) {
        cancelButton.hidden = false;
      }

      if (statusElement) {
        statusElement.hidden = true;
        statusElement.textContent = '';
      }
    };

    const showModal = ({ message, warning = false, showCancel = true, action = null, contentId = null }) => {
      if (!chatModal || !messageElement || !okButton || !warningIcon) {
        return;
      }

      currentModalAction = action;
      currentContentId = contentId;
      warningIcon.hidden = !warning;
      messageElement.textContent = message;

      if (statusElement) {
        statusElement.hidden = true;
        statusElement.textContent = '';
      }

      if (cancelButton) {
        cancelButton.hidden = !showCancel;
      }
      okButton.disabled = false;
      chatModal.hidden = false;
    };

    const recordSendAt = async (contentId) => {
      const response = await fetch('aori.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: new URLSearchParams({
          action: 'record_send_at',
          content_id: String(contentId)
        })
      });

      const result = await response.json();
      if (!response.ok || result.status !== 'ok') {
        throw new Error(result.message || '送信日時の記録に失敗しました。');
      }
    };

    const refreshFilteredResults = async () => {
      if (!filterForm || !resultsContainer) {
        window.location.reload();
        return;
      }

      const formData = new FormData(filterForm);
      const queryString = new URLSearchParams(formData).toString();
      const requestUrl = queryString ? `aori.php?${queryString}` : 'aori.php';

      const response = await fetch(requestUrl, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      if (!response.ok) {
        throw new Error('最新の一覧取得に失敗しました。');
      }

      const html = await response.text();
      const doc = parser.parseFromString(html, 'text/html');
      const nextResults = doc.getElementById('aori-results');

      if (!nextResults) {
        throw new Error('一覧エリアの更新に失敗しました。');
      }

      resultsContainer.replaceWith(nextResults);
      resultsContainer = nextResults;
    };

    const setAoriLabelMode = (mode) => {
      if (!aoriLabelModal) {
        return;
      }
      const normalizedMode = mode === 'curriculum' ? 'curriculum' : 'aori';
      currentLabelMode = normalizedMode;

      const modeInputs = aoriLabelModal.querySelectorAll('input[name="label_mode"]');
      modeInputs.forEach((input) => {
        if (!(input instanceof HTMLInputElement)) {
          return;
        }
        input.checked = input.value === normalizedMode;
      });

      const panels = aoriLabelModal.querySelectorAll('[data-label-panel]');
      panels.forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
          return;
        }
        panel.hidden = panel.dataset.labelPanel !== normalizedMode;
      });
    };
    const hideAoriLabelModal = () => {
      if (!aoriLabelModal || !aoriLabelForm) {
        return;
      }
      aoriLabelModal.hidden = true;
      currentLabelLineUserId = null;
      currentLabelMode = 'aori';
      aoriLabelForm.reset();
      if (aoriLabelStatus) {
        aoriLabelStatus.hidden = true;
        aoriLabelStatus.textContent = '';
      }
      if (aoriLabelSaveButton) {
        aoriLabelSaveButton.disabled = false;
      }
      setAoriLabelMode('aori');
    };

    const showAoriLabelModal = (lineUserId, currentLabels, currentCurriculumStatus = '') => {
      if (!aoriLabelModal || !aoriLabelForm) {
        return;
      }

      currentLabelLineUserId = lineUserId;
      const checkboxes = aoriLabelForm.querySelectorAll('input[name="labels[]"]');
      checkboxes.forEach((checkbox) => {
        if (!(checkbox instanceof HTMLInputElement)) {
          return;
        }
        checkbox.checked = currentLabels.includes(checkbox.value);
      });

      const curriculumInputs = aoriLabelForm.querySelectorAll('input[name="curriculum_status"]');
      let hasMatchedCurriculumStatus = false;
      curriculumInputs.forEach((input) => {
        if (!(input instanceof HTMLInputElement)) {
          return;
        }
        const isMatched = input.value === currentCurriculumStatus;
        input.checked = isMatched;
        if (isMatched) {
          hasMatchedCurriculumStatus = true;
        }
      });
      if (!hasMatchedCurriculumStatus) {
        const clearCurriculumInput = aoriLabelForm.querySelector('input[name="curriculum_status"][value=""]');
        if (clearCurriculumInput instanceof HTMLInputElement) {
          clearCurriculumInput.checked = true;
        }
      }
      if (aoriLabelStatus) {
        aoriLabelStatus.hidden = true;
        aoriLabelStatus.textContent = '';
      }

      if (aoriLabelSaveButton) {
        aoriLabelSaveButton.disabled = false;
      }

      aoriLabelModal.hidden = false;
    };

    const saveAoriLabels = async (lineUserId, labels, labelMode, curriculumStatus) => {
      const body = new URLSearchParams({
        action: 'save_aori_labels',
        line_user_id: String(lineUserId),
        label_mode: labelMode
      });
      if (labelMode === 'aori') {
        labels.forEach((label) => body.append('labels[]', label));
      } else {
        body.set('curriculum_status', curriculumStatus);
      }

      const response = await fetch('aori.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body
      });

      const result = await response.json();
      if (!response.ok || result.status !== 'ok') {
        throw new Error(result.message || 'ラベル保存に失敗しました。');
      }
    };
    document.addEventListener('click', (event) => {
      const editButton = event.target instanceof Element
        ? event.target.closest('[data-aori-edit-button]')
        : null;
      const chatButton = event.target instanceof Element
        ? event.target.closest('.js-chat-button')
        : null;

      const completeButton = event.target instanceof Element
        ? event.target.closest('.js-complete-button')
        : null;

      if (editButton instanceof HTMLButtonElement) {
        const lineUserId = (editButton.dataset.lineUserId || '').trim();
        let currentLabels = [];
        try {
          const rawLabels = editButton.dataset.currentLabels || '[]';
          const parsed = JSON.parse(rawLabels);
          if (Array.isArray(parsed)) {
            currentLabels = parsed.filter((item) => typeof item === 'string');
          }
        } catch (error) {
          currentLabels = [];
        }
        const currentCurriculumStatus = (editButton.dataset.currentCurriculumStatus || '').trim();
        showAoriLabelModal(lineUserId, currentLabels, currentCurriculumStatus);
        return;
      }
      if (chatButton instanceof HTMLButtonElement) {
        const friendId = (chatButton.dataset.friendId || '').trim();

        if (friendId.length === 0) {
          showModal({
            message: 'friend_idが無いため開けません。',
            warning: true,
            showCancel: false,
            action: 'alert'
          });
          return;
        }

        const chatUrl = `https://step.lme.jp/basic/chat-v3?friend_id=${encodeURIComponent(friendId)}`;
        window.open(chatUrl, '_blank', 'noopener,noreferrer');
        return;
      }

      if (!(completeButton instanceof HTMLButtonElement)) {
        return;
      }

      const contentId = Number(completeButton.dataset.contentId || '0');
      if (!Number.isFinite(contentId) || contentId <= 0) {
        showModal({
          message: '更新対象のIDが不正です。',
          warning: true,
          showCancel: false,
          action: 'alert'
        });
        return;
      }

      showModal({
        message: '煽り送信完了にします。',
        warning: false,
        showCancel: true,
        action: 'record_send_at',
        contentId
      });
    });

    okButton?.addEventListener('click', async () => {
      if (!okButton) {
        return;
      }

      if (currentModalAction !== 'record_send_at') {
        hideModal();
        return;
      }

      if (!statusElement || currentContentId === null) {
        return;
      }

      okButton.disabled = true;
      try {
        await recordSendAt(currentContentId);

        await refreshFilteredResults();
        hideModal();
      } catch (error) {
        statusElement.hidden = false;
        statusElement.textContent = error instanceof Error ? error.message : '処理に失敗しました。';
        okButton.disabled = false;
      }
    });

    cancelButton?.addEventListener('click', hideModal);
    backdrop?.addEventListener('click', hideModal);
    aoriLabelSaveButton?.addEventListener('click', async () => {
      if (!aoriLabelForm || !currentLabelLineUserId) {
        return;
      }

      const labels = Array.from(aoriLabelForm.querySelectorAll('input[name="labels[]"]:checked'))
        .filter((checkbox) => checkbox instanceof HTMLInputElement)
        .map((checkbox) => checkbox.value);
      const selectedCurriculumInput = aoriLabelForm.querySelector('input[name="curriculum_status"]:checked');
      const curriculumStatus = selectedCurriculumInput instanceof HTMLInputElement
        ? selectedCurriculumInput.value
        : '';

      if (aoriLabelSaveButton) {
        aoriLabelSaveButton.disabled = true;
      }

      try {
        await saveAoriLabels(currentLabelLineUserId, labels, currentLabelMode, curriculumStatus);
        await refreshFilteredResults();
        hideAoriLabelModal();
      } catch (error) {
        if (aoriLabelStatus) {
          aoriLabelStatus.hidden = false;
          aoriLabelStatus.textContent = error instanceof Error ? error.message : '保存に失敗しました。';
        }
        if (aoriLabelSaveButton) {
          aoriLabelSaveButton.disabled = false;
        }
      }
    });

    aoriLabelModal?.addEventListener('change', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement)) {
        return;
      }
      if (target.name !== 'label_mode') {
        return;
      }
      setAoriLabelMode(target.value);
    });
    aoriLabelCancelButton?.addEventListener('click', hideAoriLabelModal);
    aoriLabelBackdrop?.addEventListener('click', hideAoriLabelModal);
  });
})();