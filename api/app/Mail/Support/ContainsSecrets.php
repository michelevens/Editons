<?php

namespace App\Mail\Support;

/**
 * An email whose body carries a secret: a password reset link, an email
 * verification link, an invitation token.
 *
 * The email log keeps only its metadata — never the body, never a string
 * it was built from — so the log cannot be used to take over an account.
 * Built only from models, it can still be resent (the invitation: a new
 * mail from the same row); built from a link, it cannot (ask for a new
 * reset instead).
 */
interface ContainsSecrets {}
