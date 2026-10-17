<?php
session_start();
include 'db.php';

$error = "";
$username = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare(
        "SELECT * FROM users WHERE username = ? AND password = ?"
    );

    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        session_regenerate_id(true);

        // Existing session variables.
        $_SESSION['user_id']  = $row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['name']     = $row['name'];
        $_SESSION['role']     = $row['role'];
        $_SESSION['position'] = $row['position'];

        $stmt->close();

        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Invalid username or password!";
    }

    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - JPCS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 30px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: "Segoe UI", Arial, sans-serif;
            color: #fff;
            color-scheme: dark;
            background:
                radial-gradient(
                    ellipse at 80% 10%,
                    rgba(255, 125, 0, 0.22),
                    transparent 45%
                ),
                radial-gradient(
                    ellipse at 10% 90%,
                    rgba(255, 125, 0, 0.18),
                    transparent 45%
                ),
                #090909;
        }

        .login-container {
            position: relative;
            isolation: isolate;
            width: 100%;
            max-width: 430px;
            padding: 38px 32px;
            overflow: hidden;
            text-align: center;
            border: 1px solid rgba(255, 166, 70, 0.4);
            border-radius: 22px;
            background:
                radial-gradient(
                    ellipse at top right,
                    rgba(255, 133, 10, 0.17),
                    transparent 60%
                ),
                rgba(23, 20, 17, 0.88);
            box-shadow:
                0 24px 65px rgba(0, 0, 0, 0.5),
                0 0 35px rgba(255, 125, 0, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .login-container::before {
            content: "";
            position: absolute;
            z-index: -1;
            pointer-events: none;
            width: 260px;
            height: 95px;
            right: -110px;
            top: -30px;
            border-radius: 50%;
            transform: rotate(-30deg);
            border: 1px solid rgba(255, 166, 70, 0.4);
            background: linear-gradient(
                150deg,
                transparent,
                rgba(255, 133, 10, 0.24)
            );
            box-shadow: 0 10px 35px rgba(255, 125, 0, 0.13);
        }

        .brand {
            margin: 0;
            color: #fff;
            font-size: 36px;
            font-weight: 800;
            letter-spacing: 3px;
        }

        .brand span {
            color: #ff981f;
        }

        .organization {
            margin: 10px 0 25px;
            color: #c9bfb5;
            font-size: 12px;
            line-height: 1.7;
        }

        .divider {
            width: 48px;
            height: 3px;
            margin: 0 auto 25px;
            border-radius: 20px;
            background: #ff850a;
            box-shadow: 0 0 15px rgba(255, 133, 10, 0.6);
        }

        h2 {
            margin: 0 0 25px;
            color: #fff;
            font-size: 27px;
            font-weight: 750;
        }

        form {
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #eee7e0;
            font-size: 14px;
            font-weight: 600;
        }

        .field {
            margin-bottom: 20px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            min-height: 48px;
            padding: 12px 14px;
            font-family: inherit;
            font-size: 16px;
            color: #fff;
            background: rgba(8, 8, 8, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 11px;
        }

        input::placeholder {
            color: #a69b91;
        }

        input:focus {
            outline: 2px solid #ff981f;
            outline-offset: 2px;
            border-color: #ff981f;
            box-shadow: 0 0 18px rgba(255, 133, 10, 0.12);
        }

        button {
            width: 100%;
            min-height: 48px;
            margin-top: 5px;
            padding: 12px;
            font-family: inherit;
            font-size: 16px;
            font-weight: 750;
            color: #17100a;
            background: linear-gradient(135deg, #ffa52f, #ff7900);
            border: 1px solid rgba(255, 184, 107, 0.5);
            border-radius: 11px;
            cursor: pointer;
            box-shadow: 0 6px 22px rgba(255, 115, 0, 0.24);
        }

        button:hover {
            background: linear-gradient(135deg, #ffb64c, #ff921a);
            box-shadow: 0 6px 30px rgba(255, 133, 10, 0.4);
        }

        .error {
            margin: 20px 0 0;
            padding: 12px;
            color: #fff;
            background: rgba(255, 133, 10, 0.1);
            border: 1px solid #ffae55;
            border-radius: 10px;
            font-size: 14px;
            line-height: 1.6;
        }

        .back-link {
            margin: 25px 0 0;
            color: #c9bfb5;
            font-size: 14px;
        }

        a {
            color: #ffae55;
            text-decoration: none;
            font-weight: 600;
        }

        a:hover {
            color: #ffd1a0;
            text-decoration: underline;
        }

        a:focus-visible,
        button:focus-visible {
            outline: 3px solid #ffae55;
            outline-offset: 4px;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 30px 24px;
            }

            .brand {
                font-size: 32px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <p class="brand">JP<span>C</span>S</p>

        <p class="organization">
            Junior Philippine Computer Society<br>
            City of Malabon University
        </p>

        <div class="divider"></div>

        <h2>Login</h2>

        <form method="POST">
            <div class="field">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Username"
                    autocomplete="username"
                    value="<?php
                        echo htmlspecialchars(
                            $username,
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    ?>"
                    required>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Password"
                    autocomplete="current-password"
                    required>
            </div>

            <button type="submit">Login</button>
        </form>

        <?php if ($error !== '') { ?>
            <p class="error" role="alert">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </p>
        <?php } ?>

        <p class="back-link">
            Back to <a href="index.php">Landing Page</a>
        </p>
    </div>
</body>
</html>