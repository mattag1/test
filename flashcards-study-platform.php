<?php
/**
 * Plugin Name: Flashcards Study Platform
 * Description: Flashcards study platform implementing decks, cards, reviews, sessions, classes, and more.
 * Version: 0.2.0
 * Author: OpenAI
 */

if (!defined('ABSPATH')) {
    exit;
}

class FSP_Plugin {
    const DECK_POST_TYPE = 'fsp_deck';
    const CARD_POST_TYPE = 'fsp_card';

    public static function init() {
        add_action('init', [__CLASS__, 'register_post_types']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_shortcode('flashcard_deck', [__CLASS__, 'deck_shortcode']);
    }

    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = [];

        $sql[] = "CREATE TABLE " . self::table('study_sessions') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            deck_id bigint(20) unsigned NOT NULL,
            mode varchar(20) NOT NULL,
            device varchar(20) NOT NULL,
            started_at datetime NOT NULL,
            ended_at datetime DEFAULT NULL,
            cards_seen int DEFAULT 0,
            accuracy float DEFAULT 0,
            time_spent_sec int DEFAULT 0,
            streak_delta int DEFAULT 0,
            PRIMARY KEY (id),
            KEY user_idx (user_id),
            KEY deck_idx (deck_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('reviews') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            card_id bigint(20) unsigned NOT NULL,
            reviewed_at datetime NOT NULL,
            grade tinyint NOT NULL,
            ease_factor float NOT NULL,
            interval_days int NOT NULL,
            repetitions int NOT NULL,
            next_review_at datetime NOT NULL,
            latency_ms int DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY user_card (user_id, card_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('classes') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            teacher_id bigint(20) unsigned NOT NULL,
            name varchar(191) NOT NULL,
            code varchar(20) NOT NULL,
            description text,
            banner_url text,
            settings longtext,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('class_memberships') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            class_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(20) NOT NULL,
            joined_at datetime NOT NULL,
            removed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY class_idx (class_id),
            KEY user_idx (user_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('assignments') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            class_id bigint(20) unsigned NOT NULL,
            deck_id bigint(20) unsigned NOT NULL,
            title varchar(191) NOT NULL,
            instructions text,
            due_at datetime DEFAULT NULL,
            time_limit_min int DEFAULT NULL,
            mode varchar(20) NOT NULL,
            required_accuracy float DEFAULT 0,
            required_cards int DEFAULT 0,
            attempts_allowed int DEFAULT NULL,
            PRIMARY KEY (id),
            KEY class_idx (class_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('submissions') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            assignment_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            started_at datetime NOT NULL,
            submitted_at datetime DEFAULT NULL,
            score_percent float DEFAULT 0,
            status varchar(20) NOT NULL,
            PRIMARY KEY (id),
            KEY assignment_idx (assignment_id),
            KEY user_idx (user_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('ratings') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            deck_id bigint(20) unsigned NOT NULL,
            value tinyint NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_deck (user_id, deck_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('comments') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            deck_id bigint(20) unsigned NOT NULL,
            body text NOT NULL,
            created_at datetime NOT NULL,
            parent_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) DEFAULT 'visible',
            PRIMARY KEY (id),
            KEY deck_idx (deck_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('reports') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reporter_id bigint(20) unsigned NOT NULL,
            target_type varchar(20) NOT NULL,
            target_id bigint(20) unsigned NOT NULL,
            reason text NOT NULL,
            notes text,
            created_at datetime NOT NULL,
            status varchar(20) DEFAULT 'open',
            moderator_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id)
        ) $charset;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'fsp_' . $name;
    }

    public static function register_post_types() {
        register_post_type(self::DECK_POST_TYPE, [
            'label' => 'Decks',
            'public' => true,
            'show_in_rest' => true,
            'supports' => ['title', 'editor', 'author', 'thumbnail'],
        ]);

        register_post_type(self::CARD_POST_TYPE, [
            'label' => 'Cards',
            'public' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'supports' => ['editor', 'author'],
        ]);

        register_post_meta(self::CARD_POST_TYPE, 'fsp_front', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'wp_kses_post',
        ]);
        register_post_meta(self::CARD_POST_TYPE, 'fsp_back', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'wp_kses_post',
        ]);
        register_post_meta(self::CARD_POST_TYPE, 'fsp_deck_id', [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => true,
        ]);
    }

    public static function register_rest_routes() {
        register_rest_route('fsp/v1', '/decks', [
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'api_list_decks'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'api_create_deck'],
                'permission_callback' => function(){ return current_user_can('publish_posts'); },
            ],
        ]);

        register_rest_route('fsp/v1', '/decks/(?P<id>\\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'api_get_deck'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('fsp/v1', '/decks/(?P<id>\\d+)/cards', [
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'api_list_cards'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'api_create_card'],
                'permission_callback' => function(){ return current_user_can('edit_posts'); },
            ],
        ]);

        register_rest_route('fsp/v1', '/study/sessions', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'api_create_session'],
            'permission_callback' => function(){ return is_user_logged_in(); },
        ]);

        register_rest_route('fsp/v1', '/study/sessions/(?P<id>\\d+)', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'api_update_session'],
            'permission_callback' => function(){ return is_user_logged_in(); },
        ]);

        register_rest_route('fsp/v1', '/study/reviews', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_review'],
            'permission_callback' => function(){ return is_user_logged_in(); },
        ]);

        register_rest_route('fsp/v1', '/classes', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'api_create_class'],
            'permission_callback' => function(){ return current_user_can('publish_posts'); },
        ]);

        register_rest_route('fsp/v1', '/classes/join', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'api_join_class'],
            'permission_callback' => function(){ return is_user_logged_in(); },
        ]);

        register_rest_route('fsp/v1', '/classes/(?P<id>\\d+)/assignments', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'api_create_assignment'],
            'permission_callback' => function(){ return current_user_can('publish_posts'); },
        ]);

        register_rest_route('fsp/v1', '/classes/(?P<id>\\d+)/gradebook', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'api_gradebook'],
            'permission_callback' => function(){ return current_user_can('publish_posts'); },
        ]);

        register_rest_route('fsp/v1', '/decks/(?P<id>\\d+)/rate', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'api_rate_deck'],
            'permission_callback' => function(){ return is_user_logged_in(); },
        ]);

        register_rest_route('fsp/v1', '/decks/(?P<id>\\d+)/comment', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'api_comment_deck'],
            'permission_callback' => function(){ return is_user_logged_in(); },
        ]);

        register_rest_route('fsp/v1', '/report', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'api_report'],
            'permission_callback' => function(){ return is_user_logged_in(); },
        ]);

        register_rest_route('fsp/v1', '/library/search', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'api_search_library'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function api_list_decks() {
        $posts = get_posts([
            'post_type' => self::DECK_POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);
        return array_map(function($p){
            return ['id' => $p->ID, 'title' => $p->post_title];
        }, $posts);
    }

    public static function api_create_deck($request) {
        $title = sanitize_text_field($request['title']);
        if (!$title) {
            return new WP_Error('invalid', 'Title required', ['status' => 400]);
        }
        $deck_id = wp_insert_post([
            'post_type' => self::DECK_POST_TYPE,
            'post_title' => $title,
            'post_content' => wp_kses_post($request['description']),
            'post_status' => 'publish',
        ]);
        return ['id' => $deck_id];
    }

    public static function api_get_deck($request) {
        $deck_id = intval($request['id']);
        $deck = get_post($deck_id);
        if (!$deck || $deck->post_type !== self::DECK_POST_TYPE) {
            return new WP_Error('not_found', 'Deck not found', ['status' => 404]);
        }
        $cards = get_posts([
            'post_type' => self::CARD_POST_TYPE,
            'meta_key' => 'fsp_deck_id',
            'meta_value' => $deck_id,
            'numberposts' => -1,
        ]);
        $card_data = array_map(function($card) {
            return [
                'id' => $card->ID,
                'front' => get_post_meta($card->ID, 'fsp_front', true),
                'back' => get_post_meta($card->ID, 'fsp_back', true),
            ];
        }, $cards);
        return [
            'id' => $deck->ID,
            'title' => $deck->post_title,
            'cards' => $card_data,
        ];
    }

    public static function api_list_cards($request) {
        $deck_id = intval($request['id']);
        $cards = get_posts([
            'post_type' => self::CARD_POST_TYPE,
            'meta_key' => 'fsp_deck_id',
            'meta_value' => $deck_id,
            'numberposts' => -1,
        ]);
        return array_map(function($card) {
            return [
                'id' => $card->ID,
                'front' => get_post_meta($card->ID, 'fsp_front', true),
                'back' => get_post_meta($card->ID, 'fsp_back', true),
            ];
        }, $cards);
    }

    public static function api_create_card($request) {
        $deck_id = intval($request['id']);
        $front = wp_kses_post($request['front']);
        $back  = wp_kses_post($request['back']);
        if (!$deck_id || !$front || !$back) {
            return new WP_Error('invalid', 'Missing parameters', ['status' => 400]);
        }
        $card_id = wp_insert_post([
            'post_type' => self::CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ]);
        update_post_meta($card_id, 'fsp_front', $front);
        update_post_meta($card_id, 'fsp_back', $back);
        update_post_meta($card_id, 'fsp_deck_id', $deck_id);
        return ['id' => $card_id];
    }

    public static function api_create_session($request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $data = [
            'user_id' => $user_id,
            'deck_id' => intval($request['deck_id']),
            'mode' => sanitize_text_field($request['mode']),
            'device' => sanitize_text_field($request['device']),
            'started_at' => current_time('mysql'),
        ];
        $wpdb->insert(self::table('study_sessions'), $data);
        return ['id' => $wpdb->insert_id];
    }

    public static function api_update_session($request) {
        global $wpdb;
        $id = intval($request['id']);
        $data = [];
        foreach (['ended_at','cards_seen','accuracy','time_spent_sec','streak_delta'] as $field) {
            if (isset($request[$field])) {
                $data[$field] = $field === 'ended_at' ? current_time('mysql') : $request[$field];
            }
        }
        if (empty($data)) {
            return new WP_Error('invalid', 'No fields', ['status'=>400]);
        }
        $wpdb->update(self::table('study_sessions'), $data, ['id'=>$id]);
        return ['updated'=>true];
    }

    public static function handle_review($request) {
        global $wpdb;
        $card_id = intval($request['card_id']);
        $grade = intval($request['grade']);
        $latency = intval($request->get_param('latency_ms'));
        $user_id = get_current_user_id();
        if (!$card_id || $grade < 0 || $grade > 5) {
            return new WP_Error('invalid', 'Invalid parameters', ['status' => 400]);
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table('reviews') . " WHERE user_id=%d AND card_id=%d",
            $user_id, $card_id
        ), ARRAY_A);
        if (!$row) {
            $row = [
                'ease_factor' => 2.5,
                'interval_days' => 0,
                'repetitions' => 0,
            ];
        }
        if ($grade < 3) {
            $row['repetitions'] = 0;
            $row['interval_days'] = 1;
        } else {
            $row['repetitions'] += 1;
            if ($row['repetitions'] == 1) {
                $row['interval_days'] = 1;
            } elseif ($row['repetitions'] == 2) {
                $row['interval_days'] = 6;
            } else {
                $row['interval_days'] = round($row['interval_days'] * $row['ease_factor']);
            }
            $row['ease_factor'] = $row['ease_factor'] + (0.1 - (5 - $grade) * (0.08 + (5 - $grade) * 0.02));
            $row['ease_factor'] = max(1.3, min(2.8, $row['ease_factor']));
            if ($latency > 10000 && in_array($grade, [3,4], true)) {
                $row['ease_factor'] = max(1.3, $row['ease_factor'] - 0.05);
            }
        }
        $row['next_review_at'] = gmdate('Y-m-d H:i:s', time() + $row['interval_days'] * DAY_IN_SECONDS);
        $data = [
            'user_id' => $user_id,
            'card_id' => $card_id,
            'reviewed_at' => current_time('mysql', true),
            'grade' => $grade,
            'ease_factor' => $row['ease_factor'],
            'interval_days' => $row['interval_days'],
            'repetitions' => $row['repetitions'],
            'next_review_at' => $row['next_review_at'],
            'latency_ms' => $latency,
        ];
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table('reviews') . " WHERE user_id=%d AND card_id=%d",
            $user_id, $card_id
        ));
        if ($existing_id) {
            $wpdb->update(self::table('reviews'), $data, ['id'=>$existing_id]);
        } else {
            $wpdb->insert(self::table('reviews'), $data);
        }
        return [
            'card_id' => $card_id,
            'next_review_at' => $row['next_review_at'],
            'interval' => $row['interval_days'],
            'ef' => $row['ease_factor'],
            'repetitions' => $row['repetitions'],
        ];
    }

    public static function api_create_class($request) {
        global $wpdb;
        $name = sanitize_text_field($request['name']);
        if (!$name) {
            return new WP_Error('invalid', 'Name required', ['status' => 400]);
        }
        $code = strtolower(wp_generate_password(6, false));
        $wpdb->insert(self::table('classes'), [
            'teacher_id' => get_current_user_id(),
            'name' => $name,
            'code' => $code,
            'description' => wp_kses_post($request['description']),
            'created_at' => current_time('mysql', true),
        ]);
        return ['id' => $wpdb->insert_id, 'code' => $code];
    }

    public static function api_join_class($request) {
        global $wpdb;
        $code = sanitize_text_field($request['code']);
        $class = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . self::table('classes') . " WHERE code=%s",
            $code
        ));
        if (!$class) {
            return new WP_Error('not_found', 'Class not found', ['status'=>404]);
        }
        $wpdb->insert(self::table('class_memberships'), [
            'class_id' => $class->id,
            'user_id' => get_current_user_id(),
            'role' => 'student',
            'joined_at' => current_time('mysql', true),
        ]);
        return ['joined' => true];
    }

    public static function api_create_assignment($request) {
        global $wpdb;
        $class_id = intval($request['id']);
        $wpdb->insert(self::table('assignments'), [
            'class_id' => $class_id,
            'deck_id' => intval($request['deck_id']),
            'title' => sanitize_text_field($request['title']),
            'instructions' => wp_kses_post($request['instructions']),
            'due_at' => $request['due_at'],
            'mode' => sanitize_text_field($request['mode']),
            'required_accuracy' => floatval($request['required_accuracy']),
            'required_cards' => intval($request['required_cards']),
            'attempts_allowed' => intval($request['attempts_allowed']),
        ]);
        return ['id' => $wpdb->insert_id];
    }

    public static function api_gradebook($request) {
        global $wpdb;
        $class_id = intval($request['id']);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.user_id, s.score_percent, s.status FROM " . self::table('submissions') . " s JOIN " . self::table('assignments') . " a ON s.assignment_id = a.id WHERE a.class_id=%d",
            $class_id
        ), ARRAY_A);
        return $rows;
    }

    public static function api_rate_deck($request) {
        global $wpdb;
        $deck_id = intval($request['id']);
        $value = intval($request['value']);
        if ($value < 1 || $value > 5) {
            return new WP_Error('invalid', 'Rating must be 1-5', ['status'=>400]);
        }
        $wpdb->replace(self::table('ratings'), [
            'user_id' => get_current_user_id(),
            'deck_id' => $deck_id,
            'value' => $value,
            'created_at' => current_time('mysql', true),
        ]);
        return ['rated' => true];
    }

    public static function api_comment_deck($request) {
        global $wpdb;
        $deck_id = intval($request['id']);
        $body = sanitize_text_field($request['body']);
        if (!$body) {
            return new WP_Error('invalid', 'Body required', ['status'=>400]);
        }
        $wpdb->insert(self::table('comments'), [
            'user_id' => get_current_user_id(),
            'deck_id' => $deck_id,
            'body' => $body,
            'created_at' => current_time('mysql', true),
        ]);
        return ['id' => $wpdb->insert_id];
    }

    public static function api_report($request) {
        global $wpdb;
        $wpdb->insert(self::table('reports'), [
            'reporter_id' => get_current_user_id(),
            'target_type' => sanitize_text_field($request['target_type']),
            'target_id' => intval($request['target_id']),
            'reason' => sanitize_text_field($request['reason']),
            'notes' => sanitize_text_field($request['notes']),
            'created_at' => current_time('mysql', true),
        ]);
        return ['id' => $wpdb->insert_id];
    }

    public static function api_search_library($request) {
        $q = sanitize_text_field($request['q']);
        $posts = get_posts([
            'post_type' => self::DECK_POST_TYPE,
            's' => $q,
            'post_status' => 'publish',
        ]);
        return array_map(function($p){
            return ['id'=>$p->ID, 'title'=>$p->post_title];
        }, $posts);
    }

    public static function deck_shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'flashcard_deck');
        $deck_id = intval($atts['id']);
        if (!$deck_id) {
            return '';
        }
        $cards = get_posts([
            'post_type' => self::CARD_POST_TYPE,
            'meta_key' => 'fsp_deck_id',
            'meta_value' => $deck_id,
            'numberposts' => -1,
        ]);
        $output = '<div class="fsp-deck">';
        foreach ($cards as $card) {
            $front = wp_kses_post(get_post_meta($card->ID, 'fsp_front', true));
            $back = wp_kses_post(get_post_meta($card->ID, 'fsp_back', true));
            $output .= '<div class="fsp-card"><div class="fsp-front">' . $front . '</div><div class="fsp-back" style="display:none">' . $back . '</div></div>';
        }
        $output .= '</div>';
        $output .= '<script>(function(){const cards=document.querySelectorAll(".fsp-card");cards.forEach(c=>c.addEventListener("click",()=>{const b=c.querySelector(".fsp-back");b.style.display=b.style.display==="none"?"block":"none";}));})();</script>';
        return $output;
    }
}

register_activation_hook(__FILE__, ['FSP_Plugin','activate']);
FSP_Plugin::init();

