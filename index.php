<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

// Check if default language is set
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en';
}

// Check if user changed language via URL query
if (isset($_GET['lang'])) {
    if ($_GET['lang'] == 'am') {
        $_SESSION['lang'] = 'am';
    } else {
        $_SESSION['lang'] = 'en';
    }
}

$current_lang = $_SESSION['lang'];

// 1. English Translations Array
$text_en = array(
    "brand" => "Office File Management",
    "home" => "Home",
    "services" => "Services",
    "about" => "About Us",
    "contact" => "Contact Us",
    "login_btn" => "Login to Portal",
    "hero_title" => "Modern & Secure Office File Management",
    "hero_desc" => "Digitize your administrative archives, send and receive documents instantly from your desk, and secure organizational memory forever.",
    "get_started" => "Get Started",
    "services_title" => "System Key Services",
    "services_desc" => "Explore the powerful digital capabilities of our archive system.",
    "service1_title" => "Instant Upload & Routing",
    "service1_desc" => "Register document metadata and upload scanned files instantly to specific departments without leaving your desk.",
    "service2_title" => "Search Documents",
    "service2_desc" => "Retrieve any letter or document from the archive within seconds using keyword search or department filters.",
    "service3_title" => "Role-Based Access",
    "service3_desc" => "Protect confidential business correspondence through strict permissions and user role boundaries.",
    "about_title" => "About Us",
    "about_desc" => "This portal is developed to eliminate administrative bottlenecks caused by paper filing. By transforming physical files into secure digital assets, the organization ensures data transparency, fast document routing, and physical storage space optimization.",
    "mission_title" => "Our Mission",
    "mission_desc" => "To empower academic and administrative offices at Debre Tabor University with a highly secure, efficient, and centralized digital platform for real-time document routing and archival, maximizing daily administrative productivity.",
    "vision_title" => "Our Vision",
    "vision_desc" => "To transform higher education institutions and government offices in Ethiopia into completely paperless, highly secure, and seamlessly automated digital administrative environments.",
    "contact_title" => "Contact Us",
    "contact_desc" => "Get in touch with Debre Tabor University IT Administration.",
    "location" => "Location",
    "location_val" => "Debre Tabor, Ethiopia",
    "email" => "Email",
    "phone" => "Phone",
    "footer_text" => "Office File Management System",
    "footer_sub" => "Debre Tabor University - GIT - Department of Computer Science"
);

// 2. Amharic Translations Array
$text_am = array(
    "brand" => "የቢሮ ፋይል አስተዳደር",
    "home" => "ዋና ገጽ",
    "services" => "አገልግሎቶች",
    "about" => "ስለ እኛ",
    "contact" => "እኛን ለማግኘት",
    "login_btn" => "ወደ ሲስተሙ መግቢያ",
    "hero_title" => "ዘመናዊ እና አስተማማኝ የቢሮ ፋይል ማስተዳደሪያ",
    "hero_desc" => "የቢሮዎን ሰነዶች በዲጂታል መልክ ያደራጁ፣ ከአንዱ የስራ ክፍል ወደ ሌላው በሰከንድ ውስጥ ያስተላልፉ፣ እና የድርጅትዎን መረጃዎች ለዘላለም በደህንነት ይጠብቁ።",
    "get_started" => "አሁን ይጀምሩ",
    "services_title" => "የሲስተሙ ዋና ዋና አገልግሎቶች",
    "services_desc" => "የድረ-ገጽ ማህደር ሲስተማችን የሚሰጣቸውን ጠንካራ አገልግሎቶች ይመልከቱ።",
    "service1_title" => "ፋይል መጫን እና ማስተላለፍ",
    "service1_desc" => "የደብዳቤዎችን ዝርዝር መረጃ በመመዝገብ፣ የፒዲኤፍ ፋይሉን እዚያው ጠረጴዛዎ ላይ ሆነው ለሚመለከተው የስራ ክፍል ያስተላልፉ።",
    "service2_title" => "ሰነዶችን መፈለግ",
    "service2_desc" => "በማህደር ውስጥ የተቀመጡ ደብዳቤዎችን በሰንጠረዥ ውስጥ በደብዳቤ ቁጥር፣ በርዕስ ወይም በስራ ክፍል ለይተው በሰከንዶች ውስጥ ይፈልጉ።",
    "service3_title" => "የስልጣን እርከን ቁጥጥር",
    "service3_desc" => "ሚስጥራዊ የሆኑ የቢሮ ደብዳቤዎችን በአድሚን፣ በማናጀር እና በሰራተኛ የስልጣን ወሰን ደህንነታቸውን ይጠብቁ።",
    "about_title" => "ስለ እኛ",
    "about_desc" => "ይህ ሲስተም የተገነባው የወረቀት ስራዎችን እና ሰነዶችን በእጅ የመፈለግን እንግልት ለማስቀረት ነው። የቢሮ ሰነዶችን ወደ ዲጂታል በመቀየር የስራ ቅልጥፍናን, ፈጣን ዝውውርን እና የቦታ ጥብቅነትን ያስወግዳል።",
    "mission_title" => "የእኛ ተልዕኮ",
    "mission_desc" => "በደብረ ታቦር ዩኒቨርሲቲ ውስጥ የሚገኙ አካዳሚክ እና አስተዳደር ቢሮዎችን እጅግ ደህንነቱ የተጠበቀ፣ ቀልጣፋ እና ማዕከላዊ በሆነ የዲጂታል ሰነድ ዝውውር እና ማህደር ስርዓት ማብቃት፤ ይህም የእለት ተእለት የስራ ቅልጥፍናን በከፍተኛ ደረጃ ይጨምራል።",
    "vision_title" => "የእኛ ራዕይ",
    "vision_desc" => "በኢትዮጵያ ውስጥ ያሉ የከፍተኛ ትምህርት ተቋማትን እና የመንግስት መስሪያ ቤቶችን ሙሉ በሙሉ ወረቀት አልባ፣ እጅግ በጣም አስተማማኝ እና ያለምንም እንከን በራስ ሰር የሚሰሩ የዲጂታል አስተዳደር ክፍሎች ማድረግ።",
    "contact_title" => "እኛን ለማግኘት",
    "contact_desc" => "ከደብረ ታቦር ዩኒቨርሲቲ የአይቲ አስተዳደር ክፍል ጋር ለመገናኘት እነዚህን አድራሻዎች ይጠቀሙ።",
    "location" => "አድራሻ",
    "location_val" => "ደብረ ታቦር፣ ኢትዮጵያ",
    "email" => "ኢሜል",
    "phone" => "ስልክ",
    "footer_text" => "የቢሮ ፋይል አስተዳደር ሲስተም",
    "footer_sub" => "ደብረ ታቦር ዩኒቨርሲቲ - ጂአይቲ - የኮምፒውተር ሳይንስ ትምህርት ክፍል"
);

// Load the active language translation array
$lang = ($current_lang == 'am') ? $text_am : $text_en;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Office File Management System - Welcome</title>
    <!-- Local Bootstrap CSS -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .hero-section {
            background: linear-gradient(135deg, #1a365d 0%, #2a4d7c 100%);
            color: white;
            padding: 8% 0;
        }
        .feature-card {
            transition: transform 0.2s;
        }
        .feature-card:hover {
            transform: translateY(-5px);
        }
        .footer-custom {
            background-color: #1a365d;
            color: white;
        }
        html {
            scroll-behavior: smooth;
        }
        .nav-item-custom {
            padding-left: 20px;
            padding-right: 20px;
        }
        .card-mission {
            border-top: 4px solid #1a365d !important;
        }
        .card-vision {
            border-top: 4px solid #198754 !important;
        }
    </style>
</head>
<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm py-3 sticky-top">
    <div class="container">
        <!-- Brand Logo -->
        <a class="navbar-brand fw-bold text-primary" href="index.php" style="color: #1a365d !important;">
            <i class="bi bi-folder-fill me-2"></i><?php echo $lang['brand']; ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <!-- Center Menu Links -->
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
                <li class="nav-item nav-item-custom"><a class="nav-link active fw-semibold" href="index.php"><?php echo $lang['home']; ?></a></li>
                <li class="nav-item nav-item-custom"><a class="nav-link fw-semibold" href="#services"><?php echo $lang['services']; ?></a></li>
                <li class="nav-item nav-item-custom"><a class="nav-link fw-semibold" href="#about"><?php echo $lang['about']; ?></a></li>
                <li class="nav-item nav-item-custom"><a class="nav-link fw-semibold" href="#contact"><?php echo $lang['contact']; ?></a></li>
            </ul>
            <!-- Language Selector -->
            <div class="me-3">
                <?php if ($current_lang == 'en'): ?>
                    <a href="index.php?lang=am" class="btn btn-sm btn-outline-secondary fw-bold">አማርኛ</a>
                <?php else: ?>
                    <a href="index.php?lang=en" class="btn btn-sm btn-outline-secondary fw-bold">English</a>
                <?php endif; ?>
            </div>
            <!-- Login Button -->
            <a href="login.php" class="btn btn-primary fw-bold px-4" style="background-color: #1a365d; border: none;">
                <i class="bi bi-box-arrow-in-right me-1"></i> <?php echo $lang['login_btn']; ?>
            </a>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<header class="hero-section">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-7">
                <h1 class="display-4 fw-bold mb-3"><?php echo $lang['hero_title']; ?></h1>
                <p class="lead mb-4"><?php echo $lang['hero_desc']; ?></p>
                <!-- Get Started button redirects to login.php -->
                <a href="login.php" class="btn btn-light btn-lg fw-bold text-primary px-5 shadow" style="color: #1a365d !important;">
                    <?php echo $lang['get_started']; ?> <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="col-md-5 text-center d-none d-md-block">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 500" width="100%" height="280">
                    <ellipse cx="250" cy="420" rx="180" ry="15" fill="#1a365d" opacity="0.3"/>
                    <rect x="100" y="160" width="300" height="240" rx="15" fill="#2a4d7c" opacity="0.9" />
                    <rect x="120" y="140" width="260" height="240" rx="10" fill="#3b629b" />
                    <rect x="140" y="170" width="220" height="50" rx="5" fill="#eef2f7" />
                    <circle cx="250" cy="195" r="10" fill="#ffc107" />
                    <rect x="140" y="240" width="220" height="50" rx="5" fill="#eef2f7" />
                    <circle cx="250" cy="265" r="10" fill="#ffc107" />
                    <rect x="140" y="310" width="220" height="50" rx="5" fill="#eef2f7" />
                    <circle cx="250" cy="335" r="10" fill="#ffc107" />
                    <g transform="translate(180, 50)">
                        <rect x="0" y="0" width="140" height="180" rx="10" fill="#ffffff" stroke="#198754" stroke-width="5" />
                        <path d="M25 40 H115 M25 70 H115 M25 100 H85" stroke="#a0aec0" stroke-width="4" stroke-linecap="round" />
                        <circle cx="100" cy="120" r="22" fill="#198754" />
                        <path d="M90 120 L97 130 L112 112" stroke="#ffffff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                    </g>
                </svg>
            </div>
        </div>
    </div>
</header>

<!-- System Services Section -->
<section id="services" class="container my-5 py-4">
    <div class="text-center mb-5">
        <h2 class="fw-bold text-dark"><?php echo $lang['services_title']; ?></h2>
        <p class="text-secondary"><?php echo $lang['services_desc']; ?></p>
    </div>
    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card h-100 border-0 shadow-sm p-4 feature-card">
                <div class="text-primary mb-3">
                    <i class="bi bi-cloud-arrow-up-fill" style="font-size: 2.5rem; color: #1a365d;"></i>
                </div>
                <h5 class="fw-bold"><?php echo $lang['service1_title']; ?></h5>
                <p class="text-secondary mb-0"><?php echo $lang['service1_desc']; ?></p>
            </div>
        </div>
        <div class="col-md-4 mb-4">
            <div class="card h-100 border-0 shadow-sm p-4 feature-card">
                <div class="text-primary mb-3">
                    <i class="bi bi-search" style="font-size: 2.5rem; color: #1a365d;"></i>
                </div>
                <h5 class="fw-bold"><?php echo $lang['service2_title']; ?></h5>
                <p class="text-secondary mb-0"><?php echo $lang['service2_desc']; ?></p>
            </div>
        </div>
        <div class="col-md-4 mb-4">
            <div class="card h-100 border-0 shadow-sm p-4 feature-card">
                <div class="text-primary mb-3">
                    <i class="bi bi-shield-lock-fill" style="font-size: 2.5rem; color: #1a365d;"></i>
                </div>
                <h5 class="fw-bold"><?php echo $lang['service3_title']; ?></h5>
                <p class="text-secondary mb-0"><?php echo $lang['service3_desc']; ?></p>
            </div>
        </div>
    </div>
</section>

<!-- About Us Section -->
<section id="about" class="bg-white py-5 border-top">
    <div class="container my-4">
        <div class="row align-items-center mb-5">
            <div class="col-md-4 mb-4 mb-md-0 text-center">
                <i class="bi bi-building-fill text-secondary opacity-25" style="font-size: 8rem;"></i>
            </div>
            <div class="col-md-8">
                <h2 class="fw-bold text-dark mb-3"><?php echo $lang['about_title']; ?></h2>
                <p class="text-secondary" style="line-height: 1.8;"><?php echo $lang['about_desc']; ?></p>
            </div>
        </div>
        <div class="row justify-content-center g-4 mt-2">
            <div class="col-md-5">
                <div class="card h-100 border-0 shadow-sm p-4 card-mission" style="background-color: #f8f9fa;">
                    <div class="mb-3">
                        <i class="bi bi-target" style="font-size: 2.5rem; color: #1a365d;"></i>
                    </div>
                    <h5 class="fw-bold text-dark"><?php echo $lang['mission_title']; ?></h5>
                    <p class="text-secondary mb-0" style="font-size: 1.05rem; line-height: 1.7;"><?php echo $lang['mission_desc']; ?></p>
                </div>
            </div>
            <div class="col-md-5">
                <div class="card h-100 border-0 shadow-sm p-4 card-vision" style="background-color: #f8f9fa;">
                    <div class="mb-3">
                        <i class="bi bi-eye-fill" style="font-size: 2.5rem; color: #198754;"></i>
                    </div>
                    <h5 class="fw-bold text-dark"><?php echo $lang['vision_title']; ?></h5>
                    <p class="text-secondary mb-0" style="font-size: 1.05rem; line-height: 1.7;"><?php echo $lang['vision_desc']; ?></p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Contact Us Section -->
<section id="contact" class="container my-5 py-4">
    <div class="text-center mb-5">
        <h2 class="fw-bold text-dark"><?php echo $lang['contact_title']; ?></h2>
        <p class="text-secondary"><?php echo $lang['contact_desc']; ?></p>
    </div>
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="row justify-content-center g-4">
                <div class="col-md-4 text-center">
                    <div class="p-3">
                        <i class="bi bi-geo-alt-fill text-primary fs-3 mb-2" style="color: #1a365d !important;"></i>
                        <h5 class="fw-bold"><?php echo $lang['location']; ?></h5>
                        <p class="text-secondary"><?php echo $lang['location_val']; ?></p>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="p-3">
                        <i class="bi bi-envelope-fill text-primary fs-3 mb-2" style="color: #1a365d !important;"></i>
                        <h5 class="fw-bold"><?php echo $lang['email']; ?></h5>
                        <p class="text-secondary">info@dtu.edu.et</p>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="p-3">
                        <i class="bi bi-telephone-fill text-primary fs-3 mb-2" style="color: #1a365d !important;"></i>
                        <h5 class="fw-bold"><?php echo $lang['phone']; ?></h5>
                        <p class="text-secondary">+251-58-123-4567</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="footer-custom py-4 mt-auto">
    <div class="container text-center">
        <p class="mb-1 fw-semibold"><?php echo $lang['footer_text']; ?> &copy; <?php echo date('Y'); ?></p>
        <small class="text-light opacity-50"><?php echo $lang['footer_sub']; ?></small>
    </div>
</footer>

<script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>