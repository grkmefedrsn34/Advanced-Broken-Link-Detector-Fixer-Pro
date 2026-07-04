<?php
/**
 * Plugin Name: Advanced Broken Link Detector & Fixer Pro
 * Plugin URI:  
 * Description: Arka planda sunucuyu yormadan kırık linkleri tarar, e-posta ile raporlar ve panelden tek tıkla güncelleme/silme imkanı sunar.
 * Version:     2.0.0
 * Author:      Görkem Efe Dersin
 * License:     GPL2
 * Text Domain: link-detector-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Doğrudan erişimi engelle
}

/**
 * Ana Eklenti Sınıfı (OOP Standartlarında)
 */
class KLB_Broken_Link_Detector_Pro {

    private static $instance = null;
    private $option_list_key = 'klb_pro_broken_links';
    private $option_pointer_key = 'klb_pro_last_post_id';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Eklenti kurulum ve silinme kancaları
        register_activation_hook( __FILE__, array( $this, 'activate_cron' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate_cron' ) );

        // Cron Kancası
        add_action( 'klb_pro_scheduled_scan', array( $this, 'execute_safe_background_scan' ) );

        // Admin Paneli Kancaları
        add_action( 'admin_menu', array( $this, 'create_admin_menu' ) );
        add_action( 'admin_post_klb_pro_action_handler', array( $this, 'handle_admin_actions' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
    }

    /**
     * Günde 1 kez çalışacak Cron'u başlat
     */
    public function activate_cron() {
        if ( ! wp_next_scheduled( 'klb_pro_scheduled_scan' ) ) {
            wp_schedule_event( time(), 'daily', 'klb_pro_scheduled_scan' );
        }
    }

    public function deactivate_cron() {
        $timestamp = wp_next_scheduled( 'klb_pro_scheduled_scan' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'klb_pro_scheduled_scan' );
        }
        // İsteğe bağlı: Veritabanı temizliği deaktif edilirken yapılmaz, sadece uninstall.php içinde yapılır.
    }

    /**
     * Admin Paneli İçin Basit CSS Yüklemesi
     */
    public function enqueue_admin_styles( $hook ) {
        if ( 'toplevel_page_klb-pro-dashboard' !== $hook ) return;
        echo '<style>
            .klb-card { background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-top: 20px; border-radius: 4px; }
            .klb-badge { padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
            .klb-badge-danger { background: #fbeaea; color: #dc3232; }
            .klb-update-form { display: inline-flex; gap: 5px; width: 100%; }
            .klb-update-form input[type="text"] { flex-grow: 1; height: 28px; font-size: 12px; }
        </style>';
    }

    /**
     * KİLİTLENMEYİ ÖNLEYEN ARKA PLAN TARAYICISI (CRON)
     */
    public function execute_safe_background_scan() {
        global $wpdb;

        // Ticari Sır 🚀: Düşük limit tutarak bellek aşımını (Memory Limit) ve Timeout'u tamamen önlüyoruz.
        $posts_per_batch = 10; 
        $last_processed_id = get_option( $this->option_pointer_key, 0 );

        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_content, post_title FROM {$wpdb->posts} 
             WHERE post_status = 'publish' AND post_type = 'post' AND ID > %d 
             ORDER BY ID ASC LIMIT %d",
            $last_processed_id, $posts_per_batch
        ) );

        // Eğer taranacak yazı bittiyse: Sıfırla, Rapor Mailleri Gönder ve Çık
        if ( empty( $posts ) ) {
            update_option( $this->option_pointer_key, 0 );
            $this->send_report_email_to_admin();
            return;
        }

        $broken_links = get_option( $this->option_list_key, array() );
        $new_broken_found = false;

        foreach ( $posts as $post ) {
            // regex ile href ayıklama
            preg_match_all( '/href="([^"]+)"/', $post->post_content, $matches );

            if ( ! empty( $matches[1] ) ) {
                foreach ( $matches[1] as $url ) {
                    // Sadece harici geçerli web url'lerini tara (Çapa linkleri (#) veya mailto'ları akıllıca atla)
                    if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
                        
                        // Performans için sadece HTTP Header isteği atıyoruz (Gövdeyi indirmiyoruz!)
                        $response = wp_remote_head( $url, array( 
                            'timeout'    => 4, 
                            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) BrokenLinkDetectorPro/2.0'
                        ) );

                        // Eğer HEAD isteği başarısız olursa tam GET isteği ile doğrula (Bazı sunucular HEAD engeller)
                        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) == 405 ) {
                            $response = wp_remote_get( $url, array( 'timeout' => 4, 'redirection' => 2 ) );
                        }

                        $http_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

                        // 400 ve üzeri kodlar (404, 500 vb.) ya da ulaşılamayan (0) siteler kırıktır
                        if ( $http_code === 0 || $http_code >= 400 ) {
                            $link_key = md5( $post->ID . $url );
                            
                            // Zaten listede yoksa ekle
                            if ( ! isset( $broken_links[$link_key] ) ) {
                                $broken_links[$link_key] = array(
                                    'post_id'    => $post->ID,
                                    'post_title' => $post->post_title,
                                    'url'        => esc_url_raw( $url ),
                                    'status'     => $http_code === 0 ? 'Zaman Aşımı / Ulaşılamadı' : 'HTTP ' . $http_code,
                                    'discovered' => current_time( 'mysql' )
                                );
                                $new_broken_found = true;
                            }
                        }
                    }
                }
            }
            $last_processed_id = $post->ID;
        }

        update_option( $this->option_list_key, $broken_links );
        update_option( $this->option_pointer_key, $last_processed_id );

        // Ticari Sır 🚀: Eğer taranacak daha çok veri varsa, bir sonraki batch için cron beklemeden 
        // sistemi asenkron olarak arka planda hemen tetikle (Hızlı tarama)
        if ( count( $posts ) == $posts_per_batch ) {
            wp_schedule_single_event( time() + 5, 'klb_pro_scheduled_scan' );
        }
    }

    /**
     * YÖNETİCİYE RAPOR E-POSTASI GÖNDERME
     */
    private function send_report_email_to_admin() {
        $broken_links = get_option( $this->option_list_key, array() );
        if ( empty( $broken_links ) ) return;

        $admin_email = get_option( 'admin_email' );
        $subject     = '[' . get_bloginfo( 'name' ) . '] ⚠️ Kırık Link Raporu Hazır!';
        
        $message  = "Merhaba Admin,\n\nSitenizde yapılan otomatik tarama tamamlandı.\n";
        $message .= "Şu anda acil müdahale bekleyen toplam " . count( $broken_links ) . " adet kırık link tespit edildi.\n\n";
        $message .= "Detayları görmek ve tek tıkla düzeltmek için lütfen eklenti panelini ziyaret edin:\n";
        $message .= admin_url( 'admin.php?page=klb-pro-dashboard' ) . "\n\nİyi çalışmalar.";

        wp_mail( $admin_email, $subject, $message );
    }

    /**
     * TİCARİ PANEL MENÜSÜ
     */
    public function create_admin_menu() {
        add_menu_page(
            'Link Detector Pro',
            'Link Detector Pro',
            'manage_options',
            'klb-pro-dashboard',
            array( $this, 'render_admin_dashboard' ),
            'dashicons-shield-alt',
            25
        );
    }

    /**
     * GELİŞMİŞ EDİTÖR VE AKSİYON YÖNETİMİ (GÜNCELLEME / SİLME)
     */
    public function handle_admin_actions() {
        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'klb_pro_secure_nonce' ) ) {
            wp_die( 'Güvenlik doğrulaması başarısız.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        $task    = sanitize_text_field( $_REQUEST['task'] );
        $link_id = sanitize_text_field( $_REQUEST['link_id'] );
        $list    = get_option( $this->option_list_key, array() );

        if ( ! isset( $list[$link_id] ) ) {
            wp_redirect( admin_url( 'admin.php?page=klb-pro-dashboard&status=notfound' ) );
            exit;
        }

        $target = $list[$link_id];
        $post   = get_post( $target['post_id'] );

        if ( $post ) {
            $content = $post->post_content;
            $old_url = $target['url'];

            if ( 'delete' === $task ) {
                // Linki kaldır, metni koru
                $pattern = '/<a\s+[^>]*href="' . preg_quote( $old_url, '/' ) . '"[^>]*>(.*?)<\/a>/is';
                $new_content = preg_replace( $pattern, '$1', $content );
                $msg_status = 'deleted';
            } 
            elseif ( 'update' === $task && isset( $_POST['new_url'] ) ) {
                // Linki yenisiyle değiştir (KULLANICI TALEBİ 🌟)
                $new_url = esc_url_raw( $_POST['new_url'] );
                if ( ! empty( $new_url ) ) {
                    $new_content = str_replace( 'href="' . $old_url . '"', 'href="' . $new_url . '"', $content );
                    $msg_status = 'updated';
                }
            }

            if ( isset( $new_content ) ) {
                wp_update_post( array(
                    'ID'           => $target['post_id'],
                    'post_content' => $new_content
                ) );
            }
        }

        // Listeden temizle
        unset( $list[$link_id] );
        update_option( $this->option_list_key, $list );

        wp_redirect( admin_url( 'admin.php?page=klb-pro-dashboard&status=' . $msg_status ) );
        exit;
    }

    /**
     * KULLANICI DOSTU YÖNETİM PANELİ ARAYÜZÜ (UI)
     */
    public function render_admin_dashboard() {
        $list = get_option( $this->option_list_key, array() );
        $status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        
        echo '<div class="wrap"><h1>🚀 Link Detector Pro - Yönetim Paneli</h1>';

        if ( 'deleted' === $status ) echo '<div class="notice notice-success is-dismissible"><p>Link başarıyla kaldırıldı.</p></div>';
        if ( 'updated' === $status ) echo '<div class="notice notice-success is-dismissible"><p>Link başarıyla güncellendi.</p></div>';

        echo '<div class="klb-card">';
        echo '<h2>Mevcut Tarama Raporu</h2>';
        echo '<p>Eklenti arka planda asenkron çalışır. Büyük web sitelerinde dahi PHP Timeout veya hafıza limitlerine takılmaz.</p>';
        
        if ( empty( $list ) ) {
            echo '<p style="color: green; font-weight: bold; font-size:16px;">🎉 Sitenizde şu an hiç kırık link yok! Sistem güvenli durumda.</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Bulunduğu Sayfa</th><th>Hatalı Link & Yeni URL Gir</th><th>Hata Türü</th><th>Aksiyon</th></tr></thead><tbody>';
            
            foreach ( $list as $id => $item ) {
                $edit_post_url = get_edit_post_link( $item['post_id'] );
                $action_base   = admin_url( 'admin-post.php?action=klb_pro_action_handler&link_id=' . $id );
                $delete_url    = wp_nonce_url( $action_base . '&task=delete', 'klb_pro_secure_nonce' );
                $update_url    = wp_nonce_url( $action_base . '&task=update', 'klb_pro_secure_nonce' );

                echo '<tr>';
                echo "<td><a href='{$edit_post_url}' target='_blank'><strong>" . esc_html( $item['post_title'] ) . "</strong></a><br><small>Tespit: " . $item['discovered'] . "</small></td>";
                
                // Form Alanı: Kullanıcı burada linki anında güncelleyebilir
                echo "<td>
                        <code style='color:#dc3232; display:block; margin-bottom:5px;'>" . esc_html( $item['url'] ) . "</code>
                        <form method='post' action='{$update_url}' class='klb-update-form'>
                            <input type='text' name='new_url' placeholder='http://yeni-dogru-link.com' required>
                            <input type='submit' class='button button-small button-primary' value='Güncelle'>
                        </form>
                      </td>";
                
                echo "<td><span class='klb-badge klb-badge-danger'>" . esc_html( $item['status'] ) . "</span></td>";
                echo "<td><a href='{$delete_url}' class='button button-small' style='color:#dc3232; border-color:#dc3232;'>Linki Tamamen Kaldır</a></td>";
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></div>';
    }
}

// Eklentiyi Sınıf Üzerinden Güvenle Başlat
KLB_Broken_Link_Detector_Pro::get_instance();