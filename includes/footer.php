</main>
</div> <!-- End Layout -->
<?php if (isset($_SESSION['user_id'])): ?>
    <?php include_once $path_to_root . 'includes/ai_chat_widget.php'; ?>
<?php endif; ?>
<script src="<?php echo $path_to_root; ?>assets/js/main.js"></script>
</body>

</html>