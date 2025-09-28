<?php
/**
 * RTX-PHP Basic Usage Example
 * 
 * Demonstrates basic GPU-accelerated graphics operations using the RTX class
 */

require_once __DIR__ . '/../src/RTX.php';

use ProjectSaturnStudios\RTX\RTX;

echo "RTX-PHP Basic Usage Demo\n";
echo "========================\n\n";

try {
    // Initialize RTX with debug output
    $rtx = new RTX(debug: true);
    
    // Display GPU information
    $info = $rtx->getDeviceInfo();
    echo "GPU Information:\n";
    echo "  Device: {$info['name']}\n";
    echo "  CUDA Cores: " . $rtx->getCudaCores() . "\n";
    echo "  Memory: " . $rtx->getMemoryMB() . " MB\n";
    echo "  Compute Capability: {$info['major']}.{$info['minor']}\n\n";
    
    // Create a buffer (128x32 for SSD1306 compatibility)
    echo "Creating GPU buffer...\n";
    $bufferId = $rtx->createBuffer(128, 32);
    
    // Clear buffer to black
    echo "Clearing buffer...\n";
    $rtx->clear($bufferId, RTX::BLACK);
    
    // Draw some basic shapes
    echo "Drawing basic shapes...\n";
    
    // Draw pixels
    for ($i = 0; $i < 10; $i++) {
        $rtx->pixel($bufferId, 10 + $i, 10, RTX::WHITE);
    }
    
    // Draw a line
    $rtx->line($bufferId, 0, 0, 127, 31, RTX::GRAY);
    
    // Draw rectangle outline
    $rtx->rectangle($bufferId, 20, 5, 40, 20, RTX::WHITE, false);
    
    // Draw filled rectangle
    $rtx->rectangle($bufferId, 70, 10, 20, 15, RTX::WHITE, true);
    
    // Draw circle outline
    $rtx->circle($bufferId, 100, 16, 8, RTX::WHITE, false);
    
    // Draw filled circle
    $rtx->circle($bufferId, 30, 25, 5, RTX::WHITE, true);
    
    // Get buffer data
    echo "Copying buffer data...\n";
    $data = $rtx->copyBufferToArray($bufferId);
    
    // Display ASCII representation
    echo "\nBuffer Contents (ASCII Art):\n";
    echo str_repeat('-', 130) . "\n";
    
    for ($y = 0; $y < 32; $y++) {
        echo '|';
        for ($x = 0; $x < 128; $x++) {
            $pixel = $data[$y * 128 + $x];
            if ($pixel > 200) {
                echo '#';
            } elseif ($pixel > 150) {
                echo '*';
            } elseif ($pixel > 100) {
                echo '+';
            } elseif ($pixel > 50) {
                echo '.';
            } else {
                echo ' ';
            }
        }
        echo "|\n";
    }
    echo str_repeat('-', 130) . "\n\n";
    
    // Performance test
    echo "Performance Test: Drawing 1000 random pixels...\n";
    $performance = $rtx->measurePerformance(function() use ($rtx, $bufferId) {
        for ($i = 0; $i < 1000; $i++) {
            $x = rand(0, 127);
            $y = rand(0, 31);
            $color = rand(50, 255);
            $rtx->pixel($bufferId, $x, $y, $color);
        }
    });
    
    echo "  Average time: " . number_format($performance['average_ms'], 2) . " ms\n";
    echo "  FPS capability: " . number_format($performance['fps'], 1) . "\n";
    echo "  Consistency: " . number_format($performance['consistency'], 1) . "%\n\n";
    
    // Buffer information
    $bufferInfo = $rtx->getBufferInfo($bufferId);
    echo "Buffer Information:\n";
    echo "  ID: {$bufferInfo['id']}\n";
    echo "  Dimensions: {$bufferInfo['width']}x{$bufferInfo['height']}\n";
    echo "  Pixels: " . number_format($bufferInfo['pixels']) . "\n";
    echo "  Age: " . number_format($bufferInfo['age'], 3) . " seconds\n\n";
    
    // Clean up
    echo "Cleaning up...\n";
    $rtx->destroyBuffer($bufferId);
    
    echo "Basic usage demo completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
