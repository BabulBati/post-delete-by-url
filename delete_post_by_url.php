<?php
/**
 * Plugin Name: Delete Post by URL (AJAX Batch)
 * Description: Deletes (trashes) posts using their URLs via AJAX batching to reduce server load.
 * Version: 1.1
 * Author: Office WordPress Team
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Register admin menu
add_action('admin_menu', function() {
    add_menu_page(
        'Delete Post by URL',
        'Delete Post by URL',
        'manage_options',
        'delete-post-by-url',
        'dpbu_render_admin_page'
    );
});

// Render admin page
function dpbu_render_admin_page() {
    ?>
    <div class="wrap">
        <h1>Delete Multiple Posts by URL</h1>
        <form id="dpbu-form">
            <label for="dpbu_post_urls">Enter one post URL per line:</label><br>
            <textarea name="dpbu_post_urls" id="dpbu_post_urls" rows="10" style="width: 100%;" required></textarea>
            <br><br>
            <button type="submit" id="dpbu-submit" class="button button-danger">Start Deletion</button>
            <div id="dpbu-loading" style="margin-top:10px; display:none;"><em>Deleting...</em></div>
        </form>

        <ul id="dpbu-results" style="margin-top:20px;"></ul>

        <script>
        document.getElementById('dpbu-form').addEventListener('submit', function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to move these posts to trash in batches?')) return;

            const textarea = document.getElementById('dpbu_post_urls');
            const urls = textarea.value.trim().split(/\r?\n/).filter(Boolean);
            const resultsDiv = document.getElementById('dpbu-results');
            const loadingDiv = document.getElementById('dpbu-loading');
            const submitBtn = document.getElementById('dpbu-submit');

            resultsDiv.innerHTML = '';
            loadingDiv.style.display = 'block';
            submitBtn.disabled = true;

            const batchSize = 5;
            let batchIndex = 0;

            function sendNextBatch() {
                const batch = urls.slice(batchIndex, batchIndex + batchSize);
                if (batch.length === 0) {
                    loadingDiv.style.display = 'none';
                    submitBtn.disabled = false;
                    return;
                }

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'dpbu_delete_batch',
                        urls: JSON.stringify(batch),
                        _ajax_nonce: '<?php echo wp_create_nonce("dpbu_delete"); ?>'
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data && Array.isArray(data.data.messages)) {
                        data.data.messages.forEach(msg => {
                            const li = document.createElement('li');
                            li.textContent = msg;
                            resultsDiv.appendChild(li);
                        });
                    }
                    batchIndex += batchSize;
                    sendNextBatch();
                })
                .catch(err => {
                    const li = document.createElement('li');
                    li.textContent = '❌ Error: ' + err;
                    li.style.color = 'red';
                    resultsDiv.appendChild(li);
                    loadingDiv.style.display = 'none';
                    submitBtn.disabled = false;
                });
            }

            sendNextBatch();
        });
        </script>
    </div>
    <?php
}

// AJAX handler to delete posts in batch
add_action('wp_ajax_dpbu_delete_batch', function() {
    check_ajax_referer('dpbu_delete');

    if (!current_user_can('delete_posts')) {
        wp_send_json_error(['You are not allowed to delete posts.']);
    }

    $messages = [];
    $urls = isset($_POST['urls']) ? json_decode(stripslashes($_POST['urls']), true) : [];

    if (!is_array($urls)) {
        wp_send_json_error(['Invalid input.']);
    }

    wp_suspend_cache_invalidation(true);

    foreach ($urls as $url) {
        $url = esc_url_raw(trim($url));
        if (!$url) continue;

        $post_id = url_to_postid($url);
        if ($post_id) {
            $deleted = wp_delete_post($post_id, false); // Move to trash
            if ($deleted) {
                $messages[] = "✅ Post ID $post_id moved to trash.";
            } else {
                $messages[] = "❌ Failed to delete post for URL \"$url\".";
            }
        } else {
            $messages[] = "❌ No post found for URL \"$url\".";
        }
    }

    wp_suspend_cache_invalidation(false);

    wp_send_json_success(['messages' => $messages]);
});
