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
    const chatButtons = document.querySelectorAll('.js-chat-button');
    const messageElement = document.getElementById('chat-modal-message');
    const statusElement = document.getElementById('chat-modal-status');
    const warningIcon = document.getElementById('chat-warning-icon');
    const okButton = document.getElementById('chat-modal-ok');
    const cancelButton = chatModal?.querySelector('[data-chat-modal-cancel]');
    const backdrop = chatModal?.querySelector('[data-chat-modal-close]');
    let currentPayload = null;

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
        ? 'OKボタンを押すと、前回送信日時が記録され、チャット画面が開きます。'
        : 'OKボタンを押すと、friend_idが無いため、チャット画面が開きませんが前回送信日時が記録されます。';

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

    chatButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const contentId = Number(button.dataset.contentId || '0');
        const friendId = (button.dataset.friendId || '').trim();
        const hasFriendId = friendId.length > 0;
        showModal({ contentId, friendId, hasFriendId });
      });
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

        window.location.reload();
      } catch (error) {
        statusElement.hidden = false;
        statusElement.textContent = error instanceof Error ? error.message : '処理に失敗しました。';
        okButton.disabled = false;
      }
    });

    cancelButton?.addEventListener('click', hideModal);
    backdrop?.addEventListener('click', hideModal);
  });
})();