<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch user role.
$userID = (int) $_SESSION['user_id'];
$user = $conn->query(
    "SELECT role, name FROM users WHERE id='$userID'"
)->fetch_assoc();

$role = $user['role'];

// Get all officers and advisor/admin.
$result = $conn->query("
    SELECT * FROM users
    WHERE role='officer' OR role='admin_advisor'
    ORDER BY id ASC
");

function officerEscape($value) {
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Officer Information</title>

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            font-family: "Segoe UI", Arial, sans-serif;
            color: #fff;
            background:
                radial-gradient(
                    ellipse at top right,
                    rgba(255, 125, 0, 0.2),
                    transparent 45%
                ),
                radial-gradient(
                    ellipse at bottom left,
                    rgba(255, 125, 0, 0.14),
                    transparent 45%
                ),
                #090909;
            background-attachment: fixed;
        }

        /* Sidebar */
        .sidebar {
            width: 220px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding-top: 20px;
            overflow-y: auto;
            z-index: 20;
            background:
                radial-gradient(
                    ellipse at bottom left,
                    rgba(255, 125, 0, 0.23),
                    transparent 60%
                ),
                rgba(12, 12, 12, 0.94);
            border-right: 1px solid rgba(255, 152, 31, 0.28);
            box-shadow: 6px 0 30px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 24px;
            color: #fff;
            font-size: 27px;
            overflow-wrap: anywhere;
        }

        .sidebar h2::after {
            content: "";
            display: block;
            width: 42px;
            height: 3px;
            margin: 12px auto 0;
            border-radius: 20px;
            background: #ff850a;
            box-shadow: 0 0 14px rgba(255, 133, 10, 0.65);
        }

        .sidebar a {
            display: block;
            margin: 6px 10px;
            padding: 12px 16px;
            color: #e5ded7;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            line-height: 1.5;
            border-radius: 10px;
        }

        .sidebar a[href="officers.php"] {
            color: #fff;
            background: linear-gradient(
                110deg,
                rgba(255, 133, 10, 0.35),
                rgba(255, 133, 10, 0.08)
            );
            box-shadow:
                inset 3px 0 0 #ff850a,
                inset 0 0 0 1px rgba(255, 133, 10, 0.45),
                0 0 20px rgba(255, 125, 0, 0.12);
        }

        .sidebar a:hover {
            color: #fff;
            background: rgba(255, 133, 10, 0.18);
        }

        /* Content */
        .content {
            margin-left: 220px;
            padding: 20px;
            flex: 1;
            min-width: 0;
        }

        header {
            padding: 15px;
            text-align: center;
            border: 1px solid rgba(255, 166, 70, 0.3);
            border-radius: 18px;
            background:
                radial-gradient(
                    ellipse at bottom right,
                    rgba(255, 133, 10, 0.15),
                    transparent 65%
                ),
                rgba(24, 21, 18, 0.88);
            box-shadow:
                0 12px 35px rgba(0, 0, 0, 0.25),
                inset 0 1px 0 rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        header h1 {
            color: #fff;
            font-weight: 800;
            line-height: 1.3;
        }

        header h2 {
            color: #d3cac1;
            font-weight: 400;
        }

        .page-title {
            text-align: center;
            color: #ffae55;
            margin-top: 20px;
            font-size: 28px;
            line-height: 1.4;
        }

        .page-title::after {
            content: "";
            display: block;
            width: 50px;
            height: 3px;
            margin: 12px auto 0;
            border-radius: 20px;
            background: #ff850a;
            box-shadow: 0 0 15px rgba(255, 133, 10, 0.55);
        }

        /* Original officer card arrangement */
        .officer-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            justify-content: center;
            padding: 30px;
        }

        .officer-card {
            width: 220px;
            text-align: center;
            border: 1px solid rgba(255, 166, 70, 0.3);
            border-radius: 16px;
            background:
                radial-gradient(
                    ellipse at bottom right,
                    rgba(255, 133, 10, 0.18),
                    transparent 65%
                ),
                rgba(25, 21, 18, 0.88);
            box-shadow:
                0 10px 28px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            transition:
                transform 0.3s,
                border-color 0.3s,
                box-shadow 0.3s;
        }

        .officer-card:hover {
            transform: scale(1.05);
            border-color: rgba(255, 152, 31, 0.8);
            box-shadow:
                0 14px 35px rgba(0, 0, 0, 0.4),
                0 0 24px rgba(255, 125, 0, 0.2);
        }

        .officer-card img {
            display: block;
            width: 100%;
            height: 220px;
            object-fit: cover;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }

        .officer-card h4 {
            margin: 10px 0 5px;
            padding: 0 12px;
            color: #ffae55;
            font-size: 17px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .officer-card p {
            margin: 0 0 15px;
            padding: 0 12px;
            color: #eee7e0;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        a:focus-visible {
            outline: 3px solid #ffae55;
            outline-offset: 4px;
        }

        @media (max-width: 700px) {
            body {
                display: block;
            }

            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                padding: 16px 0;
                border-right: 0;
                border-bottom: 1px solid rgba(255, 152, 31, 0.28);
            }

            .sidebar h2 {
                margin: 0 0 16px;
            }

            .sidebar a {
                display: inline-block;
                margin: 4px;
                padding: 10px 12px;
                font-size: 13px;
            }

            .content {
                margin-left: 0;
                padding: 16px;
            }

            header h1 {
                font-size: 25px;
            }

            header h2 {
                font-size: 17px;
            }

            .officer-grid {
                padding: 20px 0;
                gap: 24px;
            }

            .officer-card {
                max-width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .officer-card {
                transition: none;
            }

            .officer-card:hover {
                transform: none;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2><?php echo officerEscape(ucfirst($role)); ?></h2>

        <a href="dashboard.php">Dashboard</a>
        <a href="officers.php">Officer Information</a>
        <a href="pending.php">Student Registered Pending</a>
        <a href="scanner.php">Event Attendance Scanner</a>
        <a href="attendance.php">Attendance List per Event</a>
        <a href="register_list.php">Register List</a>
        <a href="events.php">Event Management</a>

        <?php if ($role == 'admin_adviser') { ?>
            <a href="accounts.php">Account Management</a>
        <?php } ?>

        <a href="logout.php">Logout</a>
    </div>

    <div class="content">
        <header>
            <h1>Junior Philippine Computer Society</h1>
            <h2>City of Malabon University</h2>
        </header>

        <h2 class="page-title">Officer Information</h2>

        <div class="officer-grid">
            <?php while ($row = $result->fetch_assoc()) { ?>
                <div class="officer-card">
                    <?php if ($row['photo']) { ?>
                        <img
                            src="<?php echo officerEscape($row['photo']); ?>"
                            alt="<?php echo officerEscape($row['name']); ?>">
                    <?php } else { ?>
                        <img
                            src="assets/img/default.png"
                            alt="No Photo">
                    <?php } ?>

                    <h4><?php echo officerEscape($row['name']); ?></h4>
                    <p><?php echo officerEscape($row['position']); ?></p>
                </div>
            <?php } ?>
        </div>
    </div>
</body>
</html>
