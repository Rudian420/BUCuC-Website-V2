<?php
require_once '../backend/includes/auth.php';
require_admin(['can' => 'view_members']);

$dashboardLink = auth_dashboard_page();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members | BUCuC Admin</title>

    <!-- Bootstrap CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-icons.css" rel="stylesheet">
    <link href="css/templatemo-festava-live.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="css/all.min.css" />

    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">

    <style>
        .member-types-container {
            min-height: 100vh;
            background: linear-gradient(135deg, rgb(var(--c-paperText-1a1a2e, 26 26 46)) 0%, rgb(var(--c-paperText-16213e, 22 33 62)) 50%, rgb(var(--c-surface-0f3460, 15 52 96)) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }

        .member-types-card {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 3rem 2rem;
            box-shadow: 0 20px 40px rgb(var(--c-fixed-000000, 0 0 0) / 0.3);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            max-width: 500px;
            width: 100%;
            text-align: center;
        }

        .member-types-title {
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgb(var(--c-fixed-000000, 0 0 0) / 0.5);
        }

        .member-types-subtitle {
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
            font-size: 1.1rem;
            margin-bottom: 3rem;
            opacity: 0.9;
        }

        .member-type-btn {
            display: block;
            width: 100%;
            padding: 1.2rem 2rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(45deg, rgb(var(--c-paperAccent-667eea, 102 126 234)) 0%, rgb(var(--c-paperAccent-764ba2, 118 75 162)) 100%);
            border: none;
            border-radius: 15px;
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-size: 1.2rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 8px 15px rgb(var(--c-fixed-000000, 0 0 0) / 0.2);
            position: relative;
            overflow: hidden;
        }

        .member-type-btn:before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2), transparent);
            transition: left 0.5s;
        }

        .member-type-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgb(var(--c-fixed-000000, 0 0 0) / 0.3);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            text-decoration: none;
        }

        .member-type-btn:hover:before {
            left: 100%;
        }

        .member-type-btn i {
            margin-right: 0.8rem;
            font-size: 1.4rem;
        }

        /* Different colors for each button */
        .btn-panel {
            background: linear-gradient(45deg, rgb(var(--c-paperAccent-667eea, 102 126 234)) 0%, rgb(var(--c-paperAccent-764ba2, 118 75 162)) 100%);
        }

        .btn-sb {
            background: linear-gradient(45deg, rgb(var(--c-accent3-f093fb, 240 147 251)) 0%, rgb(var(--c-danger-f5576c, 245 87 108)) 100%);
        }

        .btn-asb {
            background: linear-gradient(45deg, rgb(var(--c-accent-4facfe, 79 172 254)) 0%, rgb(var(--c-accent-00f2fe, 0 242 254)) 100%);
        }

        .btn-gb {
            background: linear-gradient(45deg, rgb(var(--c-accent2-43e97b, 67 233 123)) 0%, rgb(var(--c-accent-38f9d7, 56 249 215)) 100%);
        }

        .back-btn-delicate {
            display: inline-flex;
            align-items: center;
            color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.8);
            text-decoration: none;
            padding: 0.6rem 1.2rem;
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.05);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-bottom: 2rem;
        }

        .back-btn-delicate:hover {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            border-color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3);
            transform: translateX(-5px);
        }

        @media (max-width: 768px) {
            .member-types-card {
                margin: 0 1rem;
                padding: 2rem 1.5rem;
            }

            .member-types-title {
                font-size: 2rem;
            }

            .member-type-btn {
                font-size: 1.1rem;
                padding: 1rem 1.5rem;
            }
        }
    </style>
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <!-- Member Types Selection Container -->
    <div class="member-types-container">
        <div class="member-types-card">
            <a href="<?php echo $dashboardLink; ?>" class="back-btn-delicate">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
            <h1 class="member-types-title">
                <i class="fas fa-users mb-3"></i><br>
                Member Types
            </h1>
            <p class="member-types-subtitle">
                Select the type of members you want to manage
            </p>

            <div class="member-types-buttons">

                <a href="sb_members.php" class="member-type-btn btn-sb">
                    <i class="fas fa-users-cog"></i>
                    SB Members
                </a>

                <a href="asb_panel.php" class="member-type-btn btn-asb">
                    <i class="fas fa-user-graduate"></i>
                    ASB Members
                </a>

                <a href="gb_members.php" class="member-type-btn btn-gb">
                    <i class="fas fa-users"></i>
                    GB Members
                </a>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="js/bootstrap.min.js"></script>

    <script>
        // Add smooth animations on page load
        document.addEventListener('DOMContentLoaded', function () {
            const buttons = document.querySelectorAll('.member-type-btn');
            buttons.forEach((btn, index) => {
                btn.style.opacity = '0';
                btn.style.transform = 'translateY(20px)';

                setTimeout(() => {
                    btn.style.transition = 'all 0.6s ease';
                    btn.style.opacity = '1';
                    btn.style.transform = 'translateY(0)';
                }, index * 150);
            });
        });

        // Add click effects
        document.querySelectorAll('.member-type-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                // Add ripple effect
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;

                ripple.style.cssText = `
                    position: absolute;
                    border-radius: 50%;
                    background: rgba(255, 255, 255, 0.3);
                    transform: scale(0);
                    animation: ripple 0.6s linear;
                    width: ${size}px;
                    height: ${size}px;
                    left: ${x}px;
                    top: ${y}px;
                    pointer-events: none;
                `;

                this.appendChild(ripple);

                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // CSS for ripple animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(2);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>

</html>