<?php
if (!defined('ABSPATH')) exit;
$home = class_exists('DT_Canonical') ? DT_Canonical::URL : home_url('/');
$logo = class_exists('DT_Brand') ? DT_Brand::logo_horizontal_url() : '';
$tagline = class_exists('DT_Brand') ? DT_Brand::TAGLINE : 'Typuj mecze. Zdobywaj punkty. Rywalizuj w rankingu.';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
    <style>
      body{margin:0;background:#f4f7fb;color:#07162f;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.tk-break{min-height:100vh;display:grid;place-items:center;padding:28px;box-sizing:border-box;background:radial-gradient(circle at 50% 0,rgba(5,94,251,.08),transparent 38%),#f4f7fb}.tk-break-card{width:min(720px,100%);padding:50px 38px;border:1px solid #e1e7f0;border-radius:28px;background:#fff;box-shadow:0 24px 70px rgba(7,22,47,.08);text-align:center}.tk-break-logo{display:inline-block}.tk-break-logo img{display:block;width:min(390px,78vw);height:auto;margin:auto}.tk-break-line{width:90px;height:5px;margin:25px auto;border-radius:999px;background:linear-gradient(90deg,#07162f 0 33.3%,#055efb 33.3% 66.6%,#fb5d0b 66.6%)}.tk-break h1{font-size:34px;line-height:1.1;margin:0 0 12px}.tk-break p{font-size:15px;line-height:1.65;color:#64728a;margin:0 auto;max-width:560px}.tk-break .tk-season{display:inline-block;margin-top:26px;padding:10px 16px;border-radius:999px;background:#fff2e9;color:#b84400;font-size:12px;font-weight:900;letter-spacing:.05em}.tk-footer{display:none!important}@media(max-width:600px){.tk-break-card{padding:38px 20px;border-radius:22px}.tk-break h1{font-size:28px}.tk-break p{font-size:13px}}
    </style>
</head>
<body <?php body_class('typukosza-season-break'); ?>>
<main class="tk-break">
  <section class="tk-break-card">
    <a class="tk-break-logo" href="<?php echo esc_url($home); ?>" aria-label="TypujKosza.pl — strona główna"><img src="<?php echo esc_url($logo); ?>" alt="TypujKosza.pl"></a>
    <div class="tk-break-line"></div>
    <h1>Ruszamy w sezonie 2026/2027</h1>
    <p><?php echo esc_html($tagline); ?></p>
    <span class="tk-season">DO ZOBACZENIA W NOWYM SEZONIE</span>
  </section>
</main>
<?php wp_footer(); ?>
</body>
</html>
