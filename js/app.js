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
    let currentPayload = null;

    const aoriLabelModal = document.getElementById('aori-label-modal');
    const aoriLabelForm = document.getElementById('aori-label-form');
    const aoriLabelSaveButton = document.getElementById('aori-label-save');
    const aoriLabelStatus = document.getElementById('aori-label-modal-status');
    const aoriLabelCancelButton = aoriLabelModal?.querySelector('[data-aori-modal-cancel]');
    const aoriLabelBackdrop = aoriLabelModal?.querySelector('[data-aori-modal-close]');
    let currentLabelLineUserId = null;

    const hideModal = () => {
      if (!chatModal) {
        return;
      }
      chatModal.hidden = true;
      currentPayload = null;
      if (statusElement) {
        statusElement.hidden = true;
        statusElement.textContent = '';
      }
    };

    const showModal = (payload) => {
      if (!chatModal || !messageElement || !okButton || !warningIcon) {
        return;
      }

      currentPayload = payload;
      warningIcon.hidden = payload.hasFriendId;
      messageElement.textContent = payload.hasFriendId
        ? 'OKボタンを押すと、前回煽り送信日時が記録され、チャット画面が開きます。'
        : 'OKボタンを押すと、friend_idが無いため、チャット画面が開きませんが前回煽り送信日時が記録されます。';

      if (statusElement) {
        statusElement.hidden = true;
        statusElement.textContent = '';
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

    const hideAoriLabelModal = () => {
      if (!aoriLabelModal || !aoriLabelForm) {
        return;
      }
      aoriLabelModal.hidden = true;
      currentLabelLineUserId = null;
      aoriLabelForm.reset();
      if (aoriLabelStatus) {
        aoriLabelStatus.hidden = true;
        aoriLabelStatus.textContent = '';
      }
      if (aoriLabelSaveButton) {
        aoriLabelSaveButton.disabled = false;
      }
    };

    const showAoriLabelModal = (lineUserId, currentLabels) => {
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

      if (aoriLabelStatus) {
        aoriLabelStatus.hidden = true;
        aoriLabelStatus.textContent = '';
      }

      if (aoriLabelSaveButton) {
        aoriLabelSaveButton.disabled = false;
      }

      aoriLabelModal.hidden = false;
    };

    const saveAoriLabels = async (lineUserId, labels) => {
      const body = new URLSearchParams({
        action: 'save_aori_labels',
        line_user_id: String(lineUserId)
      });
      labels.forEach((label) => body.append('labels[]', label));

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
      const button = event.target instanceof Element
        ? event.target.closest('.js-chat-button')
        : null;

      const editButton = event.target instanceof Element
        ? event.target.closest('[data-aori-edit-button]')
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
        showAoriLabelModal(lineUserId, currentLabels);
        return;
      }
      if (!(button instanceof HTMLButtonElement)) {
        return;
      }

      const contentId = Number(button.dataset.contentId || '0');
      const friendId = (button.dataset.friendId || '').trim();
      const hasFriendId = friendId.length > 0;
      showModal({ contentId, friendId, hasFriendId });
    });

    okButton?.addEventListener('click', async () => {
      if (!currentPayload || !statusElement || !okButton) {
        return;
      }

      okButton.disabled = true;
      try {
        await recordSendAt(currentPayload.contentId);

        if (currentPayload.hasFriendId) {
          const chatUrl = `https://step.lme.jp/basic/chat-v3?friend_id=${encodeURIComponent(currentPayload.friendId)}`;
          window.open(chatUrl, '_blank', 'noopener,noreferrer');
        }

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

      if (aoriLabelSaveButton) {
        aoriLabelSaveButton.disabled = true;
      }

      try {
        await saveAoriLabels(currentLabelLineUserId, labels);
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

    aoriLabelCancelButton?.addEventListener('click', hideAoriLabelModal);
    aoriLabelBackdrop?.addEventListener('click', hideAoriLabelModal);
  });
})();