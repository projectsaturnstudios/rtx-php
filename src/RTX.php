<?php
declare(strict_types=1);

namespace ProjectSaturnStudios\RTX;

use RuntimeException;
use InvalidArgumentException;

/**
 * RTX - CUDA Graphics PHP Library
 * 
 * This class provides an object-oriented interface for the CUDA Graphics PHP extension,
 * enabling GPU-accelerated graphics operations on NVIDIA Jetson devices.
 * 
 * Designed for real-time graphics rendering to displays like the SSD1306 OLED
 * in the Yahboom CUBE case, leveraging the 1024 CUDA cores of the Jetson Orin Nano.
 */
class RTX
{
    // Default buffer dimensions (SSD1306 OLED compatible)
    public const DEFAULT_WIDTH = 128;
    public const DEFAULT_HEIGHT = 32;
    
    // Maximum supported dimensions
    public const MAX_WIDTH = 1024;
    public const MAX_HEIGHT = 1024;
    
    // Maximum particles for particle systems
    public const MAX_PARTICLES = 1024;
    
    // Color constants for convenience
    public const BLACK = 0;
    public const WHITE = 255;
    public const GRAY = 128;
    
    private bool $debug;
    private ?array $deviceInfo = null;
    private array $activeBuffers = [];
    private int $bufferCounter = 0;
    
    public function __construct(bool $debug = false)
    {
        if (!extension_loaded('cuda_graphics')) {
            throw new RuntimeException('CUDA Graphics extension not found. Please install the cuda_graphics PHP extension.');
        }
        
        $this->debug = $debug;
        
        if ($this->debug) {
            echo "RTX initialized with CUDA Graphics support\n";
        }
        
        // Get device info on initialization
        $this->deviceInfo = $this->getDeviceInfo();
    }
    
    public function __destruct()
    {
        // Clean up any remaining buffers
        $this->destroyAllBuffers();
        
        if ($this->debug) {
            echo "RTX cleanup completed\n";
        }
    }
    
    /**
     * Get CUDA device information
     */
    public function getDeviceInfo(): array
    {
        if ($this->deviceInfo === null) {
            try {
                $info = \cuda_graphics_get_device_info();
                
                // Calculate CUDA cores (Jetson Orin Nano has 128 cores per SM)
                $cudaCores = ($info['multiProcessorCount'] ?? 0) * 128;
                $info['cudaCores'] = $cudaCores;
                
                // Format memory in MB
                $info['totalMemoryMB'] = round(($info['totalGlobalMem'] ?? 0) / (1024 * 1024), 1);
                
                $this->deviceInfo = $info;
                
                if ($this->debug) {
                    echo "GPU: {$info['name']}, CUDA Cores: {$cudaCores}, Memory: {$info['totalMemoryMB']} MB\n";
                }
            } catch (\Throwable $e) {
                if ($this->debug) {
                    echo "Failed to get device info: " . $e->getMessage() . "\n";
                }
                $this->deviceInfo = [];
            }
        }
        
        return $this->deviceInfo;
    }
    
    /**
     * Get the number of CUDA cores available
     */
    public function getCudaCores(): int
    {
        $info = $this->getDeviceInfo();
        return $info['cudaCores'] ?? 0;
    }
    
    /**
     * Get total GPU memory in MB
     */
    public function getMemoryMB(): float
    {
        $info = $this->getDeviceInfo();
        return $info['totalMemoryMB'] ?? 0.0;
    }
    
    /**
     * Create a new GPU buffer
     */
    public function createBuffer(int $width = self::DEFAULT_WIDTH, int $height = self::DEFAULT_HEIGHT): int
    {
        if ($width <= 0 || $width > self::MAX_WIDTH) {
            throw new InvalidArgumentException("Width must be between 1 and " . self::MAX_WIDTH);
        }
        
        if ($height <= 0 || $height > self::MAX_HEIGHT) {
            throw new InvalidArgumentException("Height must be between 1 and " . self::MAX_HEIGHT);
        }
        
        try {
            $buffer = \cuda_graphics_create_buffer($width, $height);
            
            if ($buffer === false) {
                throw new RuntimeException("Failed to create GPU buffer");
            }
            
            // Track the buffer for cleanup
            $bufferId = ++$this->bufferCounter;
            $this->activeBuffers[$bufferId] = [
                'handle' => $buffer,
                'width' => $width,
                'height' => $height,
                'created' => microtime(true)
            ];
            
            if ($this->debug) {
                echo "Created buffer #{$bufferId}: {$width}x{$height}\n";
            }
            
            return $bufferId;
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to create buffer: " . $e->getMessage());
        }
    }
    
    /**
     * Destroy a GPU buffer
     */
    public function destroyBuffer(int $bufferId): void
    {
        if (!isset($this->activeBuffers[$bufferId])) {
            if ($this->debug) {
                echo "Buffer #{$bufferId} not found or already destroyed\n";
            }
            return;
        }
        
        try {
            $buffer = $this->activeBuffers[$bufferId]['handle'];
            \cuda_graphics_destroy_buffer($buffer);
            
            unset($this->activeBuffers[$bufferId]);
            
            if ($this->debug) {
                echo "Destroyed buffer #{$bufferId}\n";
            }
        } catch (\Throwable $e) {
            if ($this->debug) {
                echo "Error destroying buffer #{$bufferId}: " . $e->getMessage() . "\n";
            }
        }
    }
    
    /**
     * Destroy all active buffers
     */
    public function destroyAllBuffers(): void
    {
        foreach (array_keys($this->activeBuffers) as $bufferId) {
            $this->destroyBuffer($bufferId);
        }
    }
    
    /**
     * Get buffer information
     */
    public function getBufferInfo(int $bufferId): ?array
    {
        if (!isset($this->activeBuffers[$bufferId])) {
            return null;
        }
        
        $buffer = $this->activeBuffers[$bufferId];
        return [
            'id' => $bufferId,
            'width' => $buffer['width'],
            'height' => $buffer['height'],
            'pixels' => $buffer['width'] * $buffer['height'],
            'created' => $buffer['created'],
            'age' => microtime(true) - $buffer['created']
        ];
    }
    
    /**
     * List all active buffers
     */
    public function getActiveBuffers(): array
    {
        $buffers = [];
        foreach (array_keys($this->activeBuffers) as $bufferId) {
            $buffers[] = $this->getBufferInfo($bufferId);
        }
        return $buffers;
    }
    
    /**
     * Copy buffer data to PHP array
     */
    public function copyBufferToArray(int $bufferId): array
    {
        $buffer = $this->getBufferHandle($bufferId);
        
        try {
            $data = \cuda_graphics_copy_buffer_to_array($buffer);
            
            if ($data === false) {
                throw new RuntimeException("Failed to copy buffer data");
            }
            
            return $data;
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to copy buffer to array: " . $e->getMessage());
        }
    }
    
    /**
     * Clear buffer with specified color
     */
    public function clear(int $bufferId, int $color = self::BLACK): void
    {
        $buffer = $this->getBufferHandle($bufferId);
        
        try {
            \cuda_graphics_clear_buffer($buffer, $color);
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to clear buffer: " . $e->getMessage());
        }
    }
    
    /**
     * Draw a single pixel
     */
    public function pixel(int $bufferId, int $x, int $y, int $color = self::WHITE): void
    {
        $buffer = $this->getBufferHandle($bufferId);
        $info = $this->getBufferInfo($bufferId);
        
        if ($x < 0 || $x >= $info['width'] || $y < 0 || $y >= $info['height']) {
            return; // Silently ignore out-of-bounds pixels
        }
        
        try {
            \cuda_graphics_draw_pixel($buffer, $x, $y, $color);
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to draw pixel: " . $e->getMessage());
        }
    }
    
    /**
     * Draw a line
     */
    public function line(int $bufferId, int $x1, int $y1, int $x2, int $y2, int $color = self::WHITE): void
    {
        $buffer = $this->getBufferHandle($bufferId);
        
        try {
            \cuda_graphics_draw_line($buffer, $x1, $y1, $x2, $y2, $color);
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to draw line: " . $e->getMessage());
        }
    }
    
    /**
     * Draw a rectangle
     */
    public function rectangle(int $bufferId, int $x, int $y, int $width, int $height, int $color = self::WHITE, bool $filled = false): void
    {
        $buffer = $this->getBufferHandle($bufferId);
        
        try {
            if ($filled) {
                \cuda_graphics_fill_rect($buffer, $x, $y, $width, $height, $color);
            } else {
                \cuda_graphics_draw_rect($buffer, $x, $y, $width, $height, $color);
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to draw rectangle: " . $e->getMessage());
        }
    }
    
    /**
     * Draw a circle
     */
    public function circle(int $bufferId, int $x, int $y, int $radius, int $color = self::WHITE, bool $filled = false): void
    {
        $buffer = $this->getBufferHandle($bufferId);
        
        try {
            if ($filled) {
                \cuda_graphics_fill_circle($buffer, $x, $y, $radius, $color);
            } else {
                \cuda_graphics_draw_circle($buffer, $x, $y, $radius, $color);
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to draw circle: " . $e->getMessage());
        }
    }
    
    /**
     * Generate plasma effect
     */
    public function plasma(int $bufferId, float $time, float $scale = 10.0): void
    {
        $buffer = $this->getBufferHandle($bufferId);
        
        try {
            $result = \cuda_graphics_plasma_effect($buffer, $time, $scale);
            
            if (!$result) {
                throw new RuntimeException("Plasma effect generation failed");
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to generate plasma effect: " . $e->getMessage());
        }
    }
    
    /**
     * Generate Mandelbrot fractal
     */
    public function mandelbrot(int $bufferId, float $zoom = 150.0, int $iterations = 100, float $centerX = -0.5, float $centerY = 0.0): void
    {
        $buffer = $this->getBufferHandle($bufferId);
        
        try {
            $result = \cuda_graphics_mandelbrot($buffer, $zoom, $iterations, $centerX, $centerY);
            
            if (!$result) {
                throw new RuntimeException("Mandelbrot generation failed");
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to generate Mandelbrot fractal: " . $e->getMessage());
        }
    }
    
    /**
     * Generate particle system
     */
    public function particles(int $bufferId, int $particleCount = 512, float $gravity = 0.3, float $wind = 0.1): void
    {
        $buffer = $this->getBufferHandle($bufferId);
        
        if ($particleCount <= 0 || $particleCount > self::MAX_PARTICLES) {
            throw new InvalidArgumentException("Particle count must be between 1 and " . self::MAX_PARTICLES);
        }
        
        try {
            $result = \cuda_graphics_particle_system($buffer, $particleCount, $gravity, $wind);
            
            if (!$result) {
                throw new RuntimeException("Particle system generation failed");
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to generate particle system: " . $e->getMessage());
        }
    }
    
    /**
     * Generate wave effect
     */
    public function waves(int $bufferId, float $amplitude = 1.0, float $frequency = 2.0, float $time = 0.0): void
    {
        $buffer = $this->getBufferHandle($bufferId);
        
        try {
            $result = \cuda_graphics_wave_effect($buffer, $amplitude, $frequency, $time);
            
            if (!$result) {
                throw new RuntimeException("Wave effect generation failed");
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to generate wave effect: " . $e->getMessage());
        }
    }
    
    /**
     * Convert buffer to SSD1306 format for display output
     * 
     * This method converts the GPU buffer data to the format expected by SSD1306 displays,
     * making it easy to pipe GPU-generated graphics to OLED displays.
     */
    public function toSSD1306Format(int $bufferId, int $threshold = 128): array
    {
        $info = $this->getBufferInfo($bufferId);
        if ($info === null) {
            throw new InvalidArgumentException("Invalid buffer ID: {$bufferId}");
        }
        
        $data = $this->copyBufferToArray($bufferId);
        $width = $info['width'];
        $height = $info['height'];
        
        // SSD1306 uses pages of 8 pixels each
        $pages = (int)ceil($height / 8);
        $ssd1306Data = [];
        
        for ($page = 0; $page < $pages; $page++) {
            for ($col = 0; $col < $width; $col++) {
                $byte = 0;
                
                for ($bit = 0; $bit < 8; $bit++) {
                    $y = $page * 8 + $bit;
                    
                    if ($y < $height) {
                        $pixelIndex = $y * $width + $col;
                        $pixelValue = $data[$pixelIndex] ?? 0;
                        
                        if ($pixelValue > $threshold) {
                            $byte |= (1 << $bit);
                        }
                    }
                }
                
                $ssd1306Data[] = $byte;
            }
        }
        
        return $ssd1306Data;
    }
    
    /**
     * Performance measurement helper
     */
    public function measurePerformance(callable $operation, int $iterations = 1): array
    {
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $operation();
            $end = microtime(true);
            
            $times[] = ($end - $start) * 1000; // Convert to milliseconds
        }
        
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        $fps = 1000 / $avgTime;
        
        return [
            'iterations' => $iterations,
            'times' => $times,
            'average_ms' => $avgTime,
            'min_ms' => $minTime,
            'max_ms' => $maxTime,
            'fps' => $fps,
            'consistency' => (1 - ($maxTime - $minTime) / $avgTime) * 100
        ];
    }
    
    /**
     * Get the raw buffer handle for extension functions
     */
    private function getBufferHandle(int $bufferId)
    {
        if (!isset($this->activeBuffers[$bufferId])) {
            throw new InvalidArgumentException("Invalid buffer ID: {$bufferId}");
        }
        
        return $this->activeBuffers[$bufferId]['handle'];
    }
}
