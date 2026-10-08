<?php 
require_once __DIR__ ."/functions.php";
include("./includes/header.php");

if(session_status() === PHP_SESSION_NONE){
    session_start();
}



if(isset($_SESSION["flash_empty"])){
    renderAlert($_SESSION['flash_alert']["message"],$_SESSION['flash_alert']["type"]);
    unset($_SESSION["flash_empty"]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["login_email"])) {
    $email = trim($_POST["login_email"]);
    $password = $_POST["login_password"] ?? '';

    // Query user by email
    $query = "SELECT id, name, password FROM users WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

       
        $passwordMatches = password_verify($password, $user["password"]) || ($password === $user["password"]);

        if ($passwordMatches) {
         
            session_regenerate_id(true);
           

            $_SESSION["ID"] = $user["id"];
            $_SESSION["Name"] = $user["name"];
            var_dump($_SESSION);
            

           

            $_SESSION['flash_alert'] = [
                'message' => 'Welcome back, ' . htmlspecialchars($user["name"]) . '!',
                'type' => 'success'
            ];
            

            header("Location: profile.php");
            
            exit();
        } else {
            $errorMessage = "Invalid email or password.";
        }
    } else {
        $errorMessage = "Invalid email or password.";
    }
    $stmt->close();
}



?>





    <div class="login-container">
        <div class="login-content">
            <div class="login-left">
                <img src="https://static.xx.fbcdn.net/rsrc.php/y1/r/4lCrCGs383h.svg" alt="Facebook Logo">
                <h2>Connect with friends and the world around you on Facebook.</h2>
            </div>
            <div class="login-right">
                <form id="loginForm" method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <div class="mb-3">
              <label for="email" class="form-label fw-semibold small">Email Address</label>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0 text-muted">
                  <i class="bi bi-envelope"></i>
                </span>
                <input type="email" class="form-control bg-light border-start-0 ps-0" id="email"
                  placeholder="name@example.com" required name="login_email" 
                  value="<?php echo htmlspecialchars($_POST['login_email'] ?? ''); ?>">
              </div>
            </div>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center">
                <label for="password" class="form-label fw-semibold small mb-0">Password</label>
                <a href="#" class="small text-decoration-none">Forgot password?</a>
              </div>
              <div class="input-group mt-1">
                <span class="input-group-text bg-light border-end-0 text-muted">
                  <i class="bi bi-lock"></i>
                </span>
                <input type="password" class="form-control bg-light border-start-0 ps-0" placeholder="••••••••" required
                  name="login_password">
              </div>
            </div>

            <div class="form-check mb-4">
              <input class="form-check-input" type="checkbox" id="rememberMe">
              <label class="form-check-label small text-muted" for="rememberMe">
                Remember this device
              </label>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 rounded-3 fw-semibold">
              Sign In
            </button>
</form>
                    
                    <a href="add_user.php"><button class="btn btn-secondary w-100 py-2 rounded-3 fw-semibold">Create new account</button></a>
                
                <div class="create-page-link">
                    <a href="#">Create a Page</a> for a celebrity, brand or business.
                </div>
            </div>
        </div>
    </div>
</body>
</html>
