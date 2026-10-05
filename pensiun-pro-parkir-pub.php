<?php
/**
 * Plugin Name: Pensiun Pro Parkir Publisher
 * Description: One-shot publisher for usaha parkir article. Self-deletes after run.
 * Version: 1.0.0
 * Author: pensiun.pro
 */

if (!defined('ABSPATH')) {
  $wp_load_candidates = [
    dirname(__FILE__) . '/../../../wp-load.php',
    dirname(__FILE__) . '/../../../../wp-load.php',
  ];
  $loaded = false;
  foreach ($wp_load_candidates as $c) {
    if (file_exists($c)) { require $c; $loaded = true; break; }
  }
  if (!$loaded) { http_response_code(500); echo 'wp-load missing'; exit; }
}

@set_time_limit(300);
@ini_set('memory_limit', '512M');

$trigger = isset($_GET['run']) && $_GET['run'] === '1';
$via_cli = (defined('WP_CLI') && WP_CLI);
if (!$trigger && !$via_cli && !(isset($_GET['pensiun_parkir_pub']) && $_GET['pensiun_parkir_pub'] === '1')) {
  if (defined('ABSPATH') && !isset($_GET['run'])) {
    return;
  }
}

header('Content-Type: application/json; charset=utf-8');
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$payload_path = __DIR__ . '/posts-payload.json';
if (!file_exists($payload_path) || filesize($payload_path) < 100) {
  $base = 'https://raw.githubusercontent.com/jununrs/pensiun-pro-tmp-pub/main/parkir/';
  $md5 = ["804a1be086597bb4a74fab3475bde5ce", "24ae1e83dc963cd928371cdedfba0d93", "89cfdfaf90187c2b1b7897b25dcd352f", "4efd46066a97a339403d4a3c71da2792", "e28ed4eba687a306504ab4ab7aee3630", "4e70a8b73fac97b5203c8b096cc52a3b", "c597de3eadc92a3837457cb57f7c3f18", "daec5bed684c5b1ef7d42eb778c7482f", "4f0010f6b0ec404ed9219af2ef230f2b", "d3f9aaf24396afb5ff925d899572209a", "93038bbc28ba3490df9e7a6b3791e3cd", "95bf87aa70349d5a1847bf08de4ca145", "fbd258d4f15aeaf3cdd031a5737259ae"];
  $sha = 'e26d53356549965a35845d32a734472d6431d92369ba0e581d596e5c3f22ab87';
  $b = ''; $bad = [];
  foreach ($md5 as $i => $m) {
    $c = @file_get_contents($base . sprintf('p%02d.txt', $i) . '?t=' . time());
    if ($c === false) { echo json_encode(['ok'=>false,'error'=>"fetch fail $i"]); exit(1); }
    $c = trim($c);
    if (md5($c) !== $m) $bad[] = $i;
    $b .= $c;
  }
  if ($bad || hash('sha256', $b) !== $sha) { echo json_encode(['ok'=>false,'error'=>'checksum','bad'=>$bad]); exit(2); }
  $json = gzdecode(base64_decode($b));
  if ($json === false) { echo json_encode(['ok'=>false,'error'=>'decode']); exit(3); }
  file_put_contents($payload_path, $json);
}
$posts = json_decode(file_get_contents($payload_path), true);
if (!is_array($posts)) {
  echo json_encode(['ok'=>false,'error'=>'Invalid payload']);
  exit;
}

$user = get_user_by('login', 'joen');
if (!$user) {
  foreach (get_users(['number'=>50]) as $cand) {
    if (strcasecmp($cand->display_name, 'joen') === 0 || stripos($cand->user_login, 'joen') !== false) {
      $user = $cand; break;
    }
  }
}
if (!$user) $user = get_user_by('id', 1);
if ($user) wp_set_current_user($user->ID);
$author_id = 1; // required: WordPress author user_id 1

function pensiun_ensure_cat($name) {
  $term = term_exists($name, 'category');
  if ($term) return (int)(is_array($term) ? $term['term_id'] : $term);
  $r = wp_insert_term($name, 'category', ['slug' => sanitize_title($name)]);
  if (is_wp_error($r)) return 0;
  return (int)$r['term_id'];
}

function pensiun_attach_png_gd($post_id, $alt, $title) {
  if (!function_exists('imagecreatetruecolor')) {
    return new WP_Error('no_gd', 'GD missing');
  }
  $w = 1280; $h = 720;
  $im = imagecreatetruecolor($w, $h);
  $deep = imagecolorallocate($im, 11, 45, 85);
  $navy = imagecolorallocate($im, 18, 63, 115);
  $teal = imagecolorallocate($im, 0, 140, 149);
  $bright = imagecolorallocate($im, 0, 167, 165);
  $aqua = imagecolorallocate($im, 56, 196, 193);
  $light = imagecolorallocate($im, 138, 219, 213);
  $white = imagecolorallocate($im, 255, 255, 255);
  imagefilledrectangle($im, 0, 0, $w, $h, $deep);
  imagefilledrectangle($im, 0, 180, $w, $h, $navy);
  imagefilledrectangle($im, 0, 320, $w, $h, $teal);
  imagefilledrectangle($im, 0, 460, $w, $h, $bright);
  imagefilledrectangle($im, 0, 580, $w, $h, $aqua);
  imagefilledrectangle($im, 60, 100, 600, 520, $white);
  imagestring($im, 5, 90, 140, 'Usaha Parkir Lahan Kosong', $deep);
  imagestring($im, 4, 90, 180, 'Ide usaha parkir untuk pensiunan', $teal);
  imagefilledrectangle($im, 720, 160, 1200, 520, $teal);
  for ($i = 0; $i < 4; $i++) {
    $x = 760 + $i * 100;
    imagerectangle($im, $x, 220, $x + 70, 460, $light);
  }
  $tmp = wp_tempnam('parkir-featured.png');
  $png = $tmp . '.png';
  imagepng($im, $png);
  imagedestroy($im);
  @unlink($tmp);
  $file_array = ['name' => 'usaha-parkir-lahan-kosong-featured.png', 'tmp_name' => $png];
  $attach_id = media_handle_sideload($file_array, $post_id);
  if (is_wp_error($attach_id)) {
    @unlink($png);
    return $attach_id;
  }
  set_post_thumbnail($post_id, $attach_id);
  update_post_meta($attach_id, '_wp_attachment_image_alt', $alt);
  wp_update_post(['ID'=>$attach_id, 'post_title'=>$title.' — featured']);
  return (int)$attach_id;
}

kses_remove_filters();
remove_filter('content_save_pre', 'wp_filter_post_kses');
remove_filter('content_filtered_save_pre', 'wp_filter_post_kses');

global $wpdb;
$results = [];

foreach ($posts as $p) {
  $item = ['filename'=>$p['filename'], 'slug'=>$p['slug']];
  try {
    $cat_ids = [];
    foreach ($p['categories'] as $cname) {
      $cid = pensiun_ensure_cat($cname);
      if ($cid) $cat_ids[] = $cid;
    }

    $existing = null;
    $q = get_posts(['name'=>$p['slug'], 'post_type'=>'post', 'post_status'=>'any', 'numberposts'=>1]);
    if ($q) $existing = $q[0];

    $placeholder = '<!-- pensiun-pro pending content -->';
    $postarr = [
      'post_title'   => $p['post_title'],
      'post_name'    => $p['slug'],
      'post_content' => $placeholder,
      'post_excerpt' => $p['excerpt'],
      'post_status'  => 'publish',
      'post_author'  => $author_id,
      'post_type'    => 'post',
      'post_category'=> $cat_ids,
    ];
    if ($existing) {
      $postarr['ID'] = (int)$existing->ID;
      $post_id = wp_update_post($postarr, true);
      $item['action'] = 'updated';
    } else {
      $post_id = wp_insert_post($postarr, true);
      $item['action'] = 'inserted';
    }
    if (is_wp_error($post_id)) {
      $item['ok']=false; $item['error']=$post_id->get_error_message(); $results[]=$item; continue;
    }
    $post_id = (int)$post_id;

    $content = $p['content'];
    $ok = $wpdb->update(
      $wpdb->posts,
      ['post_content' => $content],
      ['ID' => $post_id],
      ['%s'],
      ['%d']
    );
    if ($ok === false) {
      $item['ok']=false;
      $item['error']='wpdb update failed: '.$wpdb->last_error;
      $item['post_id']=$post_id;
      $results[]=$item;
      continue;
    }
    clean_post_cache($post_id);

    update_post_meta($post_id, '_pensiun_pro_seo_title', $p['seo_title']);
    update_post_meta($post_id, '_pensiun_pro_meta_description', $p['meta_description']);
    if (!empty($p['focus_keyword'])) {
      update_post_meta($post_id, '_pensiun_pro_focus_keyword', $p['focus_keyword']);
    }
    delete_post_meta($post_id, '_pensiun_pro_faq');

    $attach_id = null;
    $featured_note = '';
    if (!empty($p['image_b64'])) {
      $bin = base64_decode($p['image_b64']);
      $tmp = wp_tempnam($p['image_filename']);
      file_put_contents($tmp, $bin);
      $file_array = ['name'=>$p['image_filename'], 'tmp_name'=>$tmp];
      $attach_id = media_handle_sideload($file_array, $post_id);
      if (is_wp_error($attach_id)) {
        $featured_note = 'sideload_failed: '.$attach_id->get_error_message();
        @unlink($tmp);
        $attach_id = pensiun_attach_png_gd($post_id, $p['alt'], $p['post_title']);
        if (is_wp_error($attach_id)) {
          $item['featured_error'] = $featured_note.' | gd: '.$attach_id->get_error_message();
          $attach_id = null;
        } else {
          $featured_note = 'gd_png_fallback';
        }
      } else {
        set_post_thumbnail($post_id, $attach_id);
        update_post_meta($attach_id, '_wp_attachment_image_alt', $p['alt']);
        wp_update_post(['ID'=>$attach_id, 'post_title'=>$p['post_title'].' — featured']);
      }
    } else {
      $attach_id = pensiun_attach_png_gd($post_id, $p['alt'], $p['post_title']);
      if (is_wp_error($attach_id)) {
        $item['featured_error'] = $attach_id->get_error_message();
        $attach_id = null;
      } else {
        $featured_note = 'gd_png_only';
      }
    }

    $term_names = [];
    foreach ($cat_ids as $cid) {
      $t = get_term($cid, 'category');
      if ($t && !is_wp_error($t)) $term_names[] = $t->name;
    }

    $fresh = get_post($post_id);
    $pc = $fresh ? $fresh->post_content : '';
    $stored_excerpt = $fresh ? $fresh->post_excerpt : $p['excerpt'];
    $item['ok'] = true;
    $item['post_id'] = $post_id;
    $item['url'] = get_permalink($post_id);
    $item['featured_id'] = $attach_id ? (int)$attach_id : 0;
    $item['featured_ok'] = (bool)$attach_id;
    $item['featured_note'] = $featured_note;
    $item['alt'] = $p['alt'];
    $item['categories'] = $term_names;
    $item['seo_title'] = $p['seo_title'];
    $item['meta_description'] = $p['meta_description'];
    $item['focus_keyword'] = $p['focus_keyword'];
    $item['excerpt'] = $stored_excerpt;
    $item['excerpt_len'] = mb_strlen($stored_excerpt);
    $item['author_id'] = $author_id;
    $item['content_len'] = strlen($pc);
    $item['has_style'] = (strpos($pc, '<style') !== false) && (strpos($pc, 'pensiun-artikel') !== false);
    $item['has_scoped_table'] = (strpos($pc, '<table') !== false);
    $item['has_script'] = (strpos($pc, '<script') !== false);
    $item['faq_meta'] = get_post_meta($post_id, '_pensiun_pro_faq', true);
    $item['faq_in_content'] = (strpos($pc, 'FAQPage') !== false);
    $results[] = $item;
  } catch (Throwable $e) {
    $item['ok']=false; $item['error']=$e->getMessage(); $results[]=$item;
  }
}

$ok_count = 0;
foreach ($results as $r) { if (!empty($r['ok'])) $ok_count++; }
echo json_encode([
  'ok' => $ok_count === count($results),
  'author_id' => $author_id,
  'user_id' => get_current_user_id(),
  'count' => count($results),
  'updated' => $ok_count,
  'results' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// Self-cleanup
@unlink($payload_path);
@unlink(__FILE__);
@rmdir(__DIR__);
