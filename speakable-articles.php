<?php
/**
 * Plugin Name: Speakable Articles
 * Description: Generates a speakable summary of the article on publish using OpenAI GPT-3.5 and stores it in postmeta.
 * Version: 0.1.1
 * Author: Raymon Mens
 */

function speakable_articles_generate_gpt_summary(string $content): string {
    $api_key = get_option('speakable_articles_openai_api_key', '');
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
                'content' => "You are a text writer for a voice over. You summarize articles in speakable format. Use simple language. Use short sentences. Do it all in Dutch. Don't use English words."
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
        'manage_options',
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

    register_setting('speakable_articles_settings_group', 'speakable_articles_openai_api_key');
}
add_action('admin_init', 'speakable_articles_register_settings');

function speakable_articles_openai_api_key_callback() {
    $api_key = get_option('speakable_articles_openai_api_key', '');
    echo '<input type="text" name="speakable_articles_openai_api_key" value="' . esc_attr($api_key) . '" size="40">';
}