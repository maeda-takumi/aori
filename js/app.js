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
    const navToggleButton = document.querySelector('.nav-toggle');
    const headerNavLinks = document.getElementById('header-nav-links');

    if (navToggleButton && headerNavLinks) {
      navToggleButton.addEventListener('click', () => {
        const isExpanded = navToggleButton.getAttribute('aria-expanded') === 'true';
        navToggleButton.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
        headerNavLinks.classList.toggle('is-open', !isExpanded);
      });

      headerNavLinks.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
          navToggleButton.setAttribute('aria-expanded', 'false');
          headerNavLinks.classList.remove('is-open');
        });
      });

      window.addEventListener('resize', () => {
        if (window.innerWidth > 780) {
          navToggleButton.setAttribute('aria-expanded', 'false');
          headerNavLinks.classList.remove('is-open');
        }
      });
    }
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

    const aiModal = document.getElementById('aori-ai-modal');
    const aiModelSelect = document.getElementById('aori-ai-model');
    const aiModelCard = document.getElementById('aori-ai-model-card');
    const aiModelBadge = document.getElementById('aori-ai-model-badge');
    const aiModelLimit = document.getElementById('aori-ai-model-limit');
    const aiModelFeature = document.getElementById('aori-ai-model-feature');
    const aiUserSelect = document.getElementById('aori-ai-lstep-user');
    const aiMatchNote = document.getElementById('aori-ai-match-note');
    const aiStatus = document.getElementById('aori-ai-modal-status');
    const aiResult = document.getElementById('aori-ai-result');
    const aiGenerateButton = document.getElementById('aori-ai-generate');
    const aiCancelButton = aiModal?.querySelector('[data-ai-modal-cancel]');
    const aiBackdrop = aiModal?.querySelector('[data-ai-modal-close]');
    const aiPromptOpenButton = document.getElementById('ai-prompt-open');
    const aiPromptModal = document.getElementById('ai-prompt-modal');
    const aiPromptText = document.getElementById('ai-prompt-text');
    const aiPromptStatus = document.getElementById('ai-prompt-status');
    const aiPromptSaveButton = document.getElementById('ai-prompt-save');
    const aiPromptResetButton = document.getElementById('ai-prompt-reset');
    const aiPromptCancelButton = aiPromptModal?.querySelector('[data-ai-prompt-cancel]');
    const aiPromptBackdrop = aiPromptModal?.querySelector('[data-ai-prompt-close]');
    const defaultAiPromptInstruction = [
      'あなたはLINEで学習・作業進捗をサポートする担当者です。',
      '以下の情報と会話ログから、ユーザの直近状況に合わせた自然な進捗確認メッセージを1通だけ作成してください。',
      '条件:',
      '- 日本語で、LINEにそのまま貼り付けられる文面にする',
      '- 相手を責めず、前向きで返信しやすい文面にする',
      '- 直近でユーザが行っていること、困っていること、止まっている箇所があれば具体的に触れる',
      '- 180文字以内を目安にする',
      '- 件名、説明、候補リスト、引用符は付けず、送信文のみを返す'
    ].join('\n');
    const aiPromptStorageKey = 'aori.aiPromptInstruction';
    let currentAiContact = null;

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

    const setAiStatus = (message, hidden = false) => {
      if (!aiStatus) {
        return;
      }
      aiStatus.hidden = hidden || message.length === 0;
      aiStatus.textContent = message;
    };
    const updateAiModelCard = () => {
      if (!(aiModelSelect instanceof HTMLSelectElement) || !aiModelCard) {
        return;
      }

      const selectedOption = aiModelSelect.selectedOptions[0];
      if (!selectedOption) {
        aiModelCard.hidden = true;
        return;
      }

      if (aiModelBadge) {
        const rank = selectedOption.dataset.rank || '';
        const badge = selectedOption.dataset.badge || '';
        aiModelBadge.textContent = [rank, badge].filter(Boolean).join(' / ');
      }
      if (aiModelLimit) {
        aiModelLimit.textContent = selectedOption.dataset.limit || '';
      }
      if (aiModelFeature) {
        aiModelFeature.textContent = selectedOption.dataset.feature || '';
      }
      aiModelCard.hidden = false;
    };
    const getAiPromptInstruction = () => {
      const savedPrompt = localStorage.getItem(aiPromptStorageKey);
      return savedPrompt && savedPrompt.trim().length > 0 ? savedPrompt : defaultAiPromptInstruction;
    };

    const setAiPromptStatus = (message, hidden = false) => {
      if (!aiPromptStatus) {
        return;
      }
      aiPromptStatus.hidden = hidden || message.length === 0;
      aiPromptStatus.textContent = message;
    };

    const closeHeaderNav = () => {
      if (!navToggleButton || !headerNavLinks) {
        return;
      }
      navToggleButton.setAttribute('aria-expanded', 'false');
      headerNavLinks.classList.remove('is-open');
    };

    const hideAiPromptModal = () => {
      if (!aiPromptModal) {
        return;
      }
      aiPromptModal.hidden = true;
      setAiPromptStatus('', true);
    };

    const showAiPromptModal = () => {
      if (!aiPromptModal || !(aiPromptText instanceof HTMLTextAreaElement)) {
        return;
      }
      aiPromptText.value = getAiPromptInstruction();
      setAiPromptStatus('', true);
      aiPromptModal.hidden = false;
      closeHeaderNav();
      aiPromptText.focus();
    };

    const saveAiPromptInstruction = () => {
      if (!(aiPromptText instanceof HTMLTextAreaElement)) {
        return;
      }
      const promptInstruction = aiPromptText.value.trim();
      if (promptInstruction.length === 0) {
        setAiPromptStatus('プロンプトを入力してください。');
        return;
      }
      if (promptInstruction.length > 8000) {
        setAiPromptStatus('プロンプトは8000文字以内で入力してください。');
        return;
      }
      localStorage.setItem(aiPromptStorageKey, promptInstruction);
      setAiPromptStatus('AIプロンプトを保存しました。');
    };

    const resetAiPromptInstruction = () => {
      if (!(aiPromptText instanceof HTMLTextAreaElement)) {
        return;
      }
      aiPromptText.value = defaultAiPromptInstruction;
      localStorage.removeItem(aiPromptStorageKey);
      setAiPromptStatus('初期プロンプトに戻しました。');
    };


    const hideAiModal = () => {
      if (!aiModal) {
        return;
      }
      aiModal.hidden = true;
      currentAiContact = null;
      setAiStatus('', true);
      if (aiResult instanceof HTMLTextAreaElement) {
        aiResult.value = '';
      }
      if (aiGenerateButton instanceof HTMLButtonElement) {
        aiGenerateButton.disabled = false;
      }
    };

    const fetchLstepUsers = async (lineDisplayName) => {
      const response = await fetch('aori.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: new URLSearchParams({
          action: 'list_lstep_users',
          line_display_name: lineDisplayName
        })
      });

      const result = await response.json();
      if (!response.ok || result.status !== 'ok') {
        throw new Error(result.message || 'やり取りユーザ一覧の取得に失敗しました。');
      }
      return result;
    };

    const populateAiUsers = (users, matchedUserIds, selectedLstepUserId) => {
      if (!(aiUserSelect instanceof HTMLSelectElement)) {
        return;
      }
      aiUserSelect.replaceChildren();

      users.forEach((user) => {
        const option = document.createElement('option');
        option.value = String(user.id);
        const matchPrefix = user.is_exact_match ? '【LINE名一致】' : '【全ユーザ】';
        const messageCount = Number(user.message_count || 0);
        const lastMessage = user.last_message_at ? ` / 最終: ${user.last_message_at}` : '';
        const support = user.support ? ` / 担当: ${user.support}` : '';
        option.textContent = `${matchPrefix} ${user.line_name || '名称未設定'}（ID:${user.id} / ${messageCount}件${lastMessage}${support}）`;
        aiUserSelect.append(option);
      });

      const selectedId = String(selectedLstepUserId || '');
      if (selectedId && users.some((user) => String(user.id) === selectedId)) {
        aiUserSelect.value = selectedId;
      } else if (matchedUserIds.length === 1) {
        aiUserSelect.value = String(matchedUserIds[0]);
      }
    };

    const showAiModal = async (button) => {
      if (!aiModal || !(aiModelSelect instanceof HTMLSelectElement)) {
        return;
      }

      currentAiContact = {
        contactId: Number(button.dataset.contactId || '0'),
        lineUserId: (button.dataset.lineUserId || '').trim(),
        lineDisplayName: (button.dataset.lineDisplayName || '').trim(),
        selectedLstepUserId: (button.dataset.selectedLstepUserId || '').trim(),
        aiModel: (button.dataset.aiModel || '').trim()
      };

      if (!Number.isFinite(currentAiContact.contactId) || currentAiContact.contactId <= 0 || currentAiContact.lineUserId.length === 0) {
        showModal({
          message: 'AI生成対象のIDが不正です。',
          warning: true,
          showCancel: false,
          action: 'alert'
        });
        return;
      }

      if (currentAiContact.aiModel && Array.from(aiModelSelect.options).some((option) => option.value === currentAiContact.aiModel)) {
        aiModelSelect.value = currentAiContact.aiModel;
      }
      updateAiModelCard();
      if (aiResult instanceof HTMLTextAreaElement) {
        aiResult.value = '';
      }
      if (aiGenerateButton instanceof HTMLButtonElement) {
        aiGenerateButton.disabled = true;
      }
      setAiStatus('やり取りユーザ一覧を取得しています...');
      aiModal.hidden = false;

      try {
        const result = await fetchLstepUsers(currentAiContact.lineDisplayName);
        const users = Array.isArray(result.users) ? result.users : [];
        const matchedUserIds = Array.isArray(result.matched_user_ids) ? result.matched_user_ids : [];
        populateAiUsers(users, matchedUserIds, currentAiContact.selectedLstepUserId);

        if (aiMatchNote) {
          if (matchedUserIds.length === 1) {
            aiMatchNote.textContent = 'LINE名が1件一致したため自動選択しました。必要に応じて変更できます。';
          } else if (matchedUserIds.length > 1) {
            aiMatchNote.textContent = `LINE名が${matchedUserIds.length}件一致しました。正しいやり取りユーザを選択してください。`;
          } else {
            aiMatchNote.textContent = 'LINE名の完全一致がありません。全やり取りユーザから選択してください。';
          }
        }
        setAiStatus('', true);
        if (aiGenerateButton instanceof HTMLButtonElement) {
          aiGenerateButton.disabled = users.length === 0;
        }
      } catch (error) {
        setAiStatus(error instanceof Error ? error.message : 'やり取りユーザ一覧の取得に失敗しました。');
      }
    };

    const generateAiMessage = async () => {
      if (!currentAiContact || !(aiModelSelect instanceof HTMLSelectElement) || !(aiUserSelect instanceof HTMLSelectElement)) {
        return;
      }

      const lstepUserId = aiUserSelect.value;
      if (!lstepUserId) {
        setAiStatus('やり取りユーザを選択してください。');
        return;
      }

      if (aiGenerateButton instanceof HTMLButtonElement) {
        aiGenerateButton.disabled = true;
      }
      setAiStatus('AIメッセージを生成しています...');

      try {
        const response = await fetch('aori.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
          },
          body: new URLSearchParams({
            action: 'generate_ai_message',
            contact_id: String(currentAiContact.contactId),
            line_user_id: currentAiContact.lineUserId,
            lstep_user_id: lstepUserId,
            model: aiModelSelect.value,
            prompt_instruction: getAiPromptInstruction()
          })
        });

        const result = await response.json();
        if (!response.ok || result.status !== 'ok') {
          throw new Error(result.message || 'AIメッセージ生成に失敗しました。');
        }

        if (aiResult instanceof HTMLTextAreaElement) {
          aiResult.value = result.generated_message || '';
        }
        setAiStatus('生成した下書きを保存しました。');
        await refreshFilteredResults();
      } catch (error) {
        setAiStatus(error instanceof Error ? error.message : 'AIメッセージ生成に失敗しました。');
      } finally {
        if (aiGenerateButton instanceof HTMLButtonElement) {
          aiGenerateButton.disabled = false;
        }
      }
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
      labels.forEach((label) => body.append('labels[]', label));
      body.set('curriculum_status', curriculumStatus);

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

      const aiButton = event.target instanceof Element
        ? event.target.closest('.js-ai-button')
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

      if (aiButton instanceof HTMLButtonElement) {
        showAiModal(aiButton);
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

      const selectedModeInput = aoriLabelModal?.querySelector('input[name="label_mode"]:checked');
      const labelMode = selectedModeInput instanceof HTMLInputElement
        ? selectedModeInput.value
        : currentLabelMode;

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
        await saveAoriLabels(currentLabelLineUserId, labels, labelMode, curriculumStatus);
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
    aiPromptOpenButton?.addEventListener('click', showAiPromptModal);
    aiPromptCancelButton?.addEventListener('click', hideAiPromptModal);
    aiPromptBackdrop?.addEventListener('click', hideAiPromptModal);
    aiPromptSaveButton?.addEventListener('click', saveAiPromptInstruction);
    aiPromptResetButton?.addEventListener('click', resetAiPromptInstruction);
    aiCancelButton?.addEventListener('click', hideAiModal);
    aiBackdrop?.addEventListener('click', hideAiModal);
    aiModelSelect?.addEventListener('change', updateAiModelCard);
    updateAiModelCard();
    aiGenerateButton?.addEventListener('click', generateAiMessage);
  });
})();