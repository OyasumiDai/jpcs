<?php
if (function_exists('imagecreate')) {
    echo "GD library is enabled!";
} else {
    echo "GD library is NOT enabled.";
}
?>