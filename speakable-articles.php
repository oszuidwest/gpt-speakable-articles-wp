<?php
/**
 * Plugin Name: Speakable Articles
 * Description: Generates a speakable summary of the article on publish using OpenAI GPT-3.5 and stores it in postmeta.
 * Version: 0.3.1
 * Author: Raymon Mens (Streekomroep ZuidWest)
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

function speakable_articles_generate_gpt_summary(string $content): string {
    $api_key = get_option('speakable_articles_openai_api_key', '');
    $word_limit = get_option('speakable_articles_word_limit', 100);
    if (empty($api_key)) {
        return '';
    }
    $endpoint_url = 'https://api.openai.com/v1/chat/completions';

    $data = [
        'max_tokens' => 256,
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            [
                'role' => 'system',
                'content' => "Please rewrite the following news article in a clear, concise, and engaging manner that is easy to understand for a general radio audience. Do it in Dutch. Use maximal " . $word_limit . " words."
            ],
            [
                'role' => 'user',
                'content' => $content
            ]
        ]
    ];

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ];

    $ch = curl_init($endpoint_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $summary = $result['choices'][0]['message']['content'];

    return trim($summary);
}

function speakable_articles_schedule_summary_generation(int $post_id): void {
    $api_key = get_option('speakable_articles_openai_api_key', '');
    if (empty($api_key)) {
        return;
    }

    if (get_post_type($post_id) !== 'post') {
        return;
    }

    if (!wp_next_scheduled('speakable_articles_generate_summary', [$post_id])) {
        wp_schedule_single_event(time(), 'speakable_articles_generate_summary', [$post_id]);
    }
}
add_action('publish_post', 'speakable_articles_schedule_summary_generation', 10, 1);

function speakable_articles_generate_summary(int $post_id): void {
    $post = get_post($post_id);
    $content = strip_tags($post->post_content);

    $summary = speakable_articles_generate_gpt_summary($content);

    if ($summary) {
        update_post_meta($post_id, 'speakable_articles_summary', $summary);
    }
}
add_action('speakable_articles_generate_summary', 'speakable_articles_generate_summary', 10, 1);

function speakable_articles_admin_menu() {
    add_menu_page(
        'Speakable Articles Settings',
        'Speakable Articles',
        'edit_posts',
        'speakable_articles_settings',
        'speakable_articles_settings_page',
        'dashicons-microphone',
        90
    );
}
add_action('admin_menu', 'speakable_articles_admin_menu');

function speakable_articles_settings_page() {
    ?>
    <div class="wrap">
        <h1>Speakable Articles Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('speakable_articles_settings_group');
            do_settings_sections('speakable_articles_settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
add_action('admin_init', 'speakable_articles_register_settings');

function speakable_articles_register_settings() {
    add_settings_section(
        'speakable_articles_api_settings',
        'API Settings',
        null,
        'speakable_articles_settings'
    );

    add_settings_field(
        'speakable_articles_openai_api_key',
        'OpenAI API Key',
        'speakable_articles_openai_api_key_callback',
        'speakable_articles_settings',
        'speakable_articles_api_settings'
    );

    add_settings_field(
        'speakable_articles_word_limit',
        'Word Limit',
        'speakable_articles_word_limit_callback',
        'speakable_articles_settings',
        'speakable_articles_api_settings'
    );

    register_setting('speakable_articles_settings_group', 'speakable_articles_openai_api_key');
    register_setting('speakable_articles_settings_group', 'speakable_articles_word_limit');
}

function speakable_articles_openai_api_key_callback() {
    $api_key = get_option('speakable_articles_openai_api_key', '');
    echo '<input type="password" name="speakable_articles_openai_api_key" value="' . esc_attr($api_key) . '" size="40" autocomplete="off">';
}

function speakable_articles_word_limit_callback() {
    $word_limit = get_option('speakable_articles_word_limit', 100);
    echo '<input type="number" name="speakable_articles_word_limit" value="' . esc_attr($word_limit) . '" min="1">';
    echo '<p class="description">It\'s very difficult to have GPT 3.5 respect the amount of words. This can be seen as a subtle hint at best.</p>';
}

function generate_summaries_for_latest_articles() {
    check_ajax_referer('speakable_articles_generate_summaries_nonce', '_wpnonce', true);
    $args = [
        'post_type' => 'post',
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC',
        'posts_per_page' => 25
    ];
    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            if (!get_post_meta($post_id, 'speakable_articles_summary', true)) {
                speakable_articles_generate_summary($post_id);
            }
        }
    }

    wp_reset_postdata();
}
add_action('wp_ajax_generate_summaries_for_latest_articles', 'generate_summaries_for_latest_articles');
	
function speakable_articles_admin_page() {
    $api_key = get_option('speakable_articles_openai_api_key', '');

    $args = [
        'post_type' => 'post',
        'post_status' => 'publish',
        'meta_key' => 'speakable_articles_summary',
        'orderby' => 'date',
        'order' => 'DESC',
        'posts_per_page' => 25,
    ];
    $query = new WP_Query($args);

    echo '<div class="wrap">';
    echo '<h1>Latest Articles with Speakable Summaries</h1>';
    echo '<table class="wp-list-table widefat fixed striped speakable-articles-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th scope="col" id="title" class="manage-column column-title">Post Title</th>';
    echo '<th scope="col" id="summary" class="manage-column column-summary">Generated Summary</th>';
    echo '</tr>';
    echo '</thead>';

    echo '<tbody>';

    if (empty($api_key)) {
        echo '<tr><td colspan="2">Enter an OpenAI API Key to get started!</td></tr>';
    } else {
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $title = get_the_title();
                $summary = get_post_meta($post_id, 'speakable_articles_summary', true);

                echo '<tr>';
                echo '<td>' . esc_html($title) . '</td>';
                echo '<td><p>' . esc_html($summary) . '</p><p><strong><span class="copy-summary" data-summary="' . esc_attr($summary) . '" style="color: #0073aa; cursor: pointer;">&#8674; Copy Summary</span></strong></p></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="2">No articles with speakable summaries found. <a href="#" class="generate-summaries-link">But you can generate summaries for the latest 25 articles!</a></td></tr>';
        }
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    wp_reset_postdata();
}
add_action('admin_menu', function () {
    add_submenu_page(
        'speakable_articles_settings',
        'Latest Articles with Speakable Summaries',
        'Latest Summaries',
        'edit_posts',
        'speakable_articles_latest_summaries',
        'speakable_articles_admin_page'
    );
});

function speakable_articles_enqueue_admin_scripts($hook) {
    // Only enqueue the script on the speakable_articles_latest_summaries admin page
    if ($hook !== 'speakable-articles_page_speakable_articles_latest_summaries') {
        return;
    }

    wp_register_script('speakable-articles-admin', false);
    wp_enqueue_script('speakable-articles-admin');
    $nonce = wp_create_nonce('speakable_articles_generate_summaries_nonce');
    wp_localize_script('speakable-articles-admin', 'speakable_articles', array(
        'ajax_nonce' => $nonce,
    ));
    wp_add_inline_script('speakable-articles-admin', '
        document.addEventListener("DOMContentLoaded", function() {
            var table = document.querySelector(".speakable-articles-table");

            table.addEventListener("click", function(event) {
                var target = event.target;
                if (target.classList.contains("copy-summary")) {
                    var summary = target.getAttribute("data-summary");
                    var textarea = document.createElement("textarea");
                    textarea.textContent = summary;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand("copy");
                    document.body.removeChild(textarea);

                    target.textContent = "\u2713 Copied!";
                    setTimeout(function() {
                        target.textContent = "\u21e2 Copy Summary";
                    }, 1500);
                }
            });

            var generateLink = document.querySelector(".generate-summaries-link");
            if (generateLink) {
                generateLink.addEventListener("click", generateLatestSummaries);
            }
        });

        function generateLatestSummaries(event) {
            event.preventDefault();

            var link = event.target;
            link.style.pointerEvents = "none";
            link.textContent = "Generating summaries...";

            var spinner = document.createElement("span");
            spinner.className = "spinner is-active";
            spinner.style.marginLeft = "10px";
            link.parentNode.appendChild(spinner);

            var xhr = new XMLHttpRequest();
            xhr.open("POST", ajaxurl, true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded;charset=UTF-8");
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    console.log("AJAX request completed successfully.");
                }
            };
            xhr.send("action=generate_summaries_for_latest_articles&_wpnonce=" + encodeURIComponent(speakable_articles.ajax_nonce));

            setTimeout(function() {
                location.reload();
            }, 10000);
        }
    ');
}
add_action('admin_enqueue_scripts', 'speakable_articles_enqueue_admin_scripts');