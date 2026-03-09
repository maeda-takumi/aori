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
  });
})();