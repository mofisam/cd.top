<?php
// Master error page template
$error_code = isset($error_code) ? $error_code : 500;
$error_title = isset($error_title) ? $error_title : 'Server Error';
$error_description = isset($error_description) ? $error_description : 'Something went wrong on our end. Please try again later.';
$error_icon = isset($error_icon) ? $error_icon : 'fa-exclamation-triangle';
$show_search = isset($show_search) ? $show_search : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?php echo $error_code; ?> - <?php echo $error_title; ?> | checkdomain.top</title>
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        <?php include 'error.css'; ?>
    </style>
</head>
<body>
    <div class="bg-animation">
        <div></div>
        <div></div>
        <div></div>
    </div>

    <div class="error-container">
        <div class="error-logo">
            <a href="/">
                <span class="logo-text">checkdomain<span style="color: #10B981;">.</span>top</span>
            </a>
        </div>

        <div class="error-code"><?php echo $error_code; ?></div>
        
        <div class="error-icon">
            <i class="fas <?php echo $error_icon; ?> fa-3x" style="color: #3B82F6; margin-bottom: 1rem;"></i>
        </div>
        
        <h1 class="error-title"><?php echo $error_title; ?></h1>
        
        <p class="error-description"><?php echo $error_description; ?></p>
        
        <?php if ($show_search): ?>
        <form class="search-form" action="/search" method="GET">
            <div class="search-input-group">
                <input type="text" name="q" class="search-input" placeholder="Search for domains..." aria-label="Search">
                <button type="submit" class="search-button">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
        <?php endif; ?>
        
        <div class="button-group">
            <a href="/" class="btn-primary">
                <i class="fas fa-home"></i> Back to Home
            </a>
            <a href="javascript:history.back()" class="btn-secondary">
                <i class="fas fa-arrow-left"></i> Go Back
            </a>
        </div>
        
        <div class="helpful-links">
            <div class="helpful-title">You might find these helpful:</div>
            <div class="links-grid">
                <a href="/"><i class="fas fa-search"></i> Check Domain</a>
                <a href="/contact"><i class="fas fa-envelope"></i> Contact Support</a>
                <a href="/blog"><i class="fas fa-blog"></i> Blog</a>
                <a href="/faq"><i class="fas fa-question-circle"></i> FAQ</a>
            </div>
        </div>
        
        <?php if (isset($suggestions) && !empty($suggestions)): ?>
        <div class="suggestions">
            <div class="suggestions-title">
                <i class="fas fa-lightbulb"></i> Suggestions:
            </div>
            <ul class="suggestions-list">
                <?php foreach ($suggestions as $suggestion): ?>
                <li><?php echo htmlspecialchars($suggestion); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Log error to console for debugging (only in development)
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.error('Error <?php echo $error_code; ?>: <?php echo addslashes($error_title); ?>');
        }
    </script>
</body>
</html>