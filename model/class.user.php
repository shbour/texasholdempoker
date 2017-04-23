<?php

  class user extends container{
    function __construct($args = false){
      parent::__construct($args);
    }

    // try to login the user from existing session if possible
    // if session is expired, redirect to home page (login / register)
    function try_login($redirect_to_login = true){
      if(!$this->is_logged_in()){
        if($redirect_to_login){
          view::redirect("index.php");
        }
      }else{
        $user = $this->load_by_username($_SESSION['username']);
        $this->id             = $user->id;
        $this->username       = $user->username;
        $this->active_game_id = $user->active_game_id;
      }
    }

    function load_by_id($id){
      $db     = database::get_db();
      $query  = $db->prepare("SELECT * FROM `users` WHERE id=?");
      $query->bind_param("i", $id);
      $query->execute();

      $result = $query->get_result();
      if($result){
        while($row = $result->fetch_assoc()){
          return new user($row);
        }
      }
      return false;
    }

    function load_by_username($username){
      $db     = database::get_db();
      $query  = $db->prepare("SELECT * FROM `users` WHERE username=?");
      $query->bind_param("s", $username);
      $query->execute();

      $result = $query->get_result();
      while($row = $result->fetch_assoc()){
        return new user($row);
      }
      return false;
    }

    function get_active_game(){
      if(!isset($this->active_game_id) && ($this->active_game_id)){
        $db     = database::get_db();
        $query  = $db->prepare("SELECT active_game_id FROM `users` WHERE id=?");
        $query->bind_param("i", $this->id);
        $query->execute();

        $result = $query->get_result();
        while($row = $result->fetch_assoc()){
          $this->active_game_id = $row["active_game_id"];
        }
      }
      return $this->active_game_id;
    }

    function update_active_game($game_id){
      $db     = database::get_db();
      $query  = $db->prepare("UPDATE users SET active_game_id=? WHERE id=?");
      $query->bind_param("ii", $game_id, $this->id);
      $query->execute();
    }

    function get_user_agent(){
      return $_SERVER["HTTP_USER_AGENT"] ? $_SERVER["HTTP_USER_AGENT"] : "custombrowser";
    }

    // todo this function later
    function is_logged_in(){
      if(isset($_SESSION) && isset($_SESSION["username"])){
        $user_browser = $this->get_user_agent();
        $username     = isset($_SESSION["username"])?$_SESSION["username"]:null;
        $loginsalt    = isset($_SESSION["loginsalt"])?$_SESSION["loginsalt"]:null;
        if($username && $loginsalt){
          $salted_hash  = $this->get_salted_password($username);
          $logincheck   = hash('sha512', $salted_hash.$user_browser);
          if(hash_equals($logincheck, $loginsalt)){
            return true;
          }
        }
      }
      return false;
    }

    function is_unregistered_username($username){
      $db = database::get_db();
      $query  = $db->prepare("SELECT count(`id`) as count FROM `users` WHERE username = ?");
      $query->bind_param("s", $username);
      $query->execute();

      $result = $query->get_result();
      while($row = $result->fetch_assoc()){
        if(!$row["count"]){return true;}
      }
      return false;
    }

    // check the whitelist of allowed characters for username
    // only alphanumeric plus underscores + must be 5-15 characters long
    function is_allowable_username($username){
      if(preg_match('/[A-Za-z0-9_]{5,15}$/', $username)){
        return true;
      }
      return false;
    }

    // check the whitelist of allowed characters for password / see if the rules are matched
    // must be 8 characters long + contain atleast 1 alphabet, number and symbol
    function is_allowable_password($password, $password_confirm){
      if($password === $password_confirm){
        if(preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[$@$!%*#?&])[A-Za-z\d$@$!%*#?&]{8,20}$/', $password)){
          return true;
        }
      }
      return false;
    }

    // salted hash for password
    function register_user($username, $password){
      $salted_hash = password_hash($password, PASSWORD_DEFAULT);
      $db     = database::get_db();
      $query  = $db->prepare("INSERT INTO `users`(`username`, `password`) VALUES(?,?)");
      $query->bind_param("ss", $username, $salted_hash);
      $query->execute();
    }

    function register($args){
      if(isset($args["username"]) && isset($args["password"]) && isset($args["password_confirm"])){
        $username = $args["username"];
        $password = $args["password"];
        $password_confirm = $args["password_confirm"];

        if($this->is_allowable_username($username)
          && $this->is_allowable_password($password, $password_confirm)){
            if($this->is_unregistered_username($username)){
              $this->register_user($username, $password);
              $this->login($args);
              return true;
            }
          }
      }
      return false;
    }

    function initialize_session(){
      session_save_path();
      session_start();
      session_name(SESSION_NAME);

      ini_set("session.cookie_domain", SITE_ROOT);
      ini_set("session.cookie_path", "/");
      ini_set('session.cookie_lifetime', '900');
      ini_set('session.use_cookies', 1);
      ini_set('session.use_only_cookies', 1);
      ini_set('session.use_strict_mode', 1);
      ini_set('session.cookie_httponly', 1);
      ini_set('session.cookie_secure', 1);
      ini_set('session.gc_maxlifetime', 900);
      ini_set('gc_probability', 100);
      ini_set('gc_divisor', 100);
    }

    function create_login_session($username, $salted_hash){
      if(isset($_SESSION["username"]) && isset($_SESSION["loginsalt"])){
        $this->destroy_login_session();
        $this->initialize_session();
      }
      $user_browser         =   $this->get_user_agent();
      $_SESSION["username"] =   $username;
      $_SESSION['loginsalt']= hash('sha512', $salted_hash.$user_browser);
    }

    function destroy_login_session(){
      setcookie(session_name(), '', time() - 42000);
      $_SESSION = array();
      unset($_SESSION);

      session_write_close();
      session_destroy();
    }

    function get_salted_password($username){
      $db = database::get_db();
      $query  = $db->prepare("SELECT password FROM `users` WHERE username = ?");
      $query->bind_param("i", $username);
      $query->execute();
      $result = $query->get_result();
      if($result->field_count){
        return $result->fetch_assoc()["password"];
      }
      return false;
    }

    function login($args){
      if(isset($args["username"]) && isset($args["password"])){
        $username = $args["username"];
        $password = $args["password"];

        // get password associated with the user
        $salted_hash  = $this->get_salted_password($username);
        if($salted_hash && password_verify($password, $salted_hash)){
          $this->create_login_session($username, $salted_hash);
          return true;
        }
      }
      return false;
    }


    function logout(){
      $this->destroy_login_session();
    }

  }
