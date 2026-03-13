<?php
// footer.php
?>
  </main>

  <footer class="site-footer">
    <div class="container">
      <small>&copy; <?= date('Y'); ?> Bull-Fight</small>
    </div>
  </footer>

  <!-- 毎回読み込み（キャッシュバスター） -->
  <script src="js/app.js?v=<?= time(); ?>"></script>
</body>
</html>