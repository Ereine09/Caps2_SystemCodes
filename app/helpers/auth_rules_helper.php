<?php
/**
 * One set of account rules for every portal (admin, staff, rider, customer).
 *
 * - Login forms: only LENGTH limits (auth_login_input_ok). Existing accounts whose
 *   password was set before these rules can still log in.
 * - Creating an account or setting/changing a password: the full rules below.
 *
 * Every function returns null when the value is fine, or a short plain-English
 * error message to show the user.
 */

const AUTH_USERNAME_MIN = 4;
const AUTH_USERNAME_MAX = 30;
const AUTH_NAME_MIN = 2;
const AUTH_NAME_MAX = 50;        // first name / last name
const AUTH_FULL_NAME_MAX = 100;  // customer full name
const AUTH_EMAIL_MAX = 100;
const AUTH_PHONE_MAX = 20;
const AUTH_PASSWORD_MIN = 8;
const AUTH_PASSWORD_MAX = 64;    // bcrypt only uses the first 72 bytes
const AUTH_LOGIN_ID_MAX = 100;   // "username or email" box on login forms
const AUTH_LOGIN_PASSWORD_MAX = 128;

if (!function_exists('auth_password_rules_text')) {
    /** The password rules in one sentence, for form hints. */
    function auth_password_rules_text(): string
    {
        return AUTH_PASSWORD_MIN . '–' . AUTH_PASSWORD_MAX
            . ' characters, with at least one uppercase letter, one lowercase letter, one number and one symbol (e.g. ! @ # $).';
    }
}

if (!function_exists('auth_login_input_ok')) {
    /**
     * Length check for login forms. Over-long input is rejected before any database
     * lookup or password hashing (stops "huge password" slow-down attacks).
     */
    function auth_login_input_ok(string $identifier, string $password): bool
    {
        return $identifier !== '' && $password !== ''
            && mb_strlen($identifier) <= AUTH_LOGIN_ID_MAX
            && strlen($password) <= AUTH_LOGIN_PASSWORD_MAX;
    }
}

if (!function_exists('auth_username_error')) {
    function auth_username_error(string $username): ?string
    {
        $len = mb_strlen($username);
        if ($len < AUTH_USERNAME_MIN || $len > AUTH_USERNAME_MAX) {
            return 'Username must be ' . AUTH_USERNAME_MIN . '–' . AUTH_USERNAME_MAX . ' characters long.';
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._]*$/', $username)) {
            return 'Username can only use letters, numbers, dots (.) and underscores (_), and must start with a letter or number.';
        }
        return null;
    }
}

if (!function_exists('auth_name_error')) {
    /**
     * A person's name: letters (including ñ, é, …), spaces, dots, apostrophes and hyphens.
     *
     * @param string $label e.g. 'First name', 'Last name', 'Full name'
     */
    function auth_name_error(string $name, string $label = 'Name', int $max = AUTH_NAME_MAX): ?string
    {
        $len = mb_strlen($name);
        if ($len < AUTH_NAME_MIN || $len > $max) {
            return $label . ' must be ' . AUTH_NAME_MIN . '–' . $max . ' characters long.';
        }
        if (!preg_match("/^[\\p{L}][\\p{L}\\p{M} .'\\-]*$/u", $name)) {
            return $label . ' can only use letters, spaces, dots, apostrophes and hyphens.';
        }
        return null;
    }
}

if (!function_exists('auth_email_error')) {
    function auth_email_error(string $email): ?string
    {
        if (mb_strlen($email) > AUTH_EMAIL_MAX) {
            return 'Email address must be ' . AUTH_EMAIL_MAX . ' characters or fewer.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid email address.';
        }
        return null;
    }
}

if (!function_exists('auth_phone_error')) {
    function auth_phone_error(string $phone): ?string
    {
        if (mb_strlen($phone) > AUTH_PHONE_MAX) {
            return 'Phone number must be ' . AUTH_PHONE_MAX . ' characters or fewer.';
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (!preg_match('/^\+?[\d\s\-()]+$/', $phone) || strlen($digits) < 7 || strlen($digits) > 15) {
            return 'Please enter a valid phone number (numbers only, e.g. 09171234567).';
        }
        return null;
    }
}

if (!function_exists('auth_password_error')) {
    /**
     * Rules for a NEW password.
     *
     * @param string[] $avoid Things the password must not contain (username, email name, ...).
     */
    function auth_password_error(string $password, array $avoid = []): ?string
    {
        $len = mb_strlen($password);
        if ($len > AUTH_PASSWORD_MAX) {
            return 'Password must be ' . AUTH_PASSWORD_MAX . ' characters or fewer.';
        }
        if (trim($password) !== $password) {
            return 'Password cannot start or end with a space.';
        }
        $missing = [];
        if ($len < AUTH_PASSWORD_MIN) $missing[] = 'at least ' . AUTH_PASSWORD_MIN . ' characters';
        if (!preg_match('/\p{Lu}/u', $password)) $missing[] = 'an uppercase letter';
        if (!preg_match('/\p{Ll}/u', $password)) $missing[] = 'a lowercase letter';
        if (!preg_match('/\d/', $password)) $missing[] = 'a number';
        if (!preg_match('/[^\p{L}\p{N}\s]/u', $password)) $missing[] = 'a symbol (e.g. ! @ # $)';
        if ($missing) {
            return 'Password needs ' . implode(', ', $missing) . '.';
        }
        $lower = mb_strtolower($password);
        foreach ($avoid as $value) {
            $value = mb_strtolower(trim((string) $value));
            if (strpos($value, '@') !== false) {
                $value = strstr($value, '@', true); // the part of an email before @
            }
            // Check the whole value and each word of it ("Juan Dela Cruz" -> juan, dela, cruz).
            $words = array_merge([$value], preg_split('/[\s._\-]+/u', $value) ?: []);
            foreach ($words as $word) {
                if (mb_strlen($word) >= 4 && strpos($lower, $word) !== false) {
                    return 'Password must not contain your username, name or email.';
                }
            }
        }
        $common = ['password', 'passw0rd', 'qwerty', '12345678', 'iloveyou', 'admin123', 'welcome', 'letmein', 'abc12345'];
        foreach ($common as $c) {
            if (strpos(preg_replace('/[^a-z0-9]/', '', $lower), $c) !== false) {
                return 'That password is too easy to guess. Please choose another one.';
            }
        }
        return null;
    }
}
