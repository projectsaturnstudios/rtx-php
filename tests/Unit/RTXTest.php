<?php

use ProjectSaturnStudios\RTX\RTX;

describe('RTX Class', function () {
    
    beforeEach(function () {
        skipIfNoCudaGraphics();
    });
    
    it('can be instantiated', function () {
        $rtx = new RTX();
        expect($rtx)->toBeInstanceOf(RTX::class);
    });
    
    it('throws exception when cuda_graphics extension is not loaded', function () {
        // This test would need to mock extension_loaded() to return false
        // For now, we'll skip this test when the extension is available
        if (extension_loaded('cuda_graphics')) {
            $this->markTestSkipped('CUDA Graphics extension is loaded');
        }
        
        expect(fn() => new RTX())->toThrow(RuntimeException::class);
    });
    
    it('can get device information', function () {
        $rtx = new RTX();
        $info = $rtx->getDeviceInfo();
        
        expect($info)->toBeArray();
        expect($info)->toHaveKeys(['name', 'major', 'minor', 'multiProcessorCount', 'totalGlobalMem']);
    });
    
    it('can get CUDA cores count', function () {
        $rtx = new RTX();
        $cores = $rtx->getCudaCores();
        
        expect($cores)->toBeInt();
        expect($cores)->toBeGreaterThan(0);
    });
    
    it('can get memory in MB', function () {
        $rtx = new RTX();
        $memory = $rtx->getMemoryMB();
        
        expect($memory)->toBeFloat();
        expect($memory)->toBeGreaterThan(0);
    });
    
});

describe('Buffer Management', function () {
    
    beforeEach(function () {
        skipIfNoCudaGraphics();
        $this->rtx = new RTX();
    });
    
    it('can create a buffer', function () {
        $bufferId = $this->rtx->createBuffer(128, 32);
        
        expect($bufferId)->toBeInt();
        expect($bufferId)->toBeGreaterThan(0);
        
        $this->rtx->destroyBuffer($bufferId);
    });
    
    it('can create buffer with default dimensions', function () {
        $bufferId = $this->rtx->createBuffer();
        
        expect($bufferId)->toBeInt();
        expect($bufferId)->toBeGreaterThan(0);
        
        $info = $this->rtx->getBufferInfo($bufferId);
        expect($info['width'])->toBe(RTX::DEFAULT_WIDTH);
        expect($info['height'])->toBe(RTX::DEFAULT_HEIGHT);
        
        $this->rtx->destroyBuffer($bufferId);
    });
    
    it('validates buffer dimensions', function () {
        expect(fn() => $this->rtx->createBuffer(0, 32))
            ->toThrow(InvalidArgumentException::class);
            
        expect(fn() => $this->rtx->createBuffer(128, 0))
            ->toThrow(InvalidArgumentException::class);
            
        expect(fn() => $this->rtx->createBuffer(2000, 32))
            ->toThrow(InvalidArgumentException::class);
    });
    
    it('can get buffer information', function () {
        $bufferId = $this->rtx->createBuffer(64, 48);
        $info = $this->rtx->getBufferInfo($bufferId);
        
        expect($info)->toBeArray();
        expect($info)->toHaveKeys(['id', 'width', 'height', 'pixels', 'created', 'age']);
        expect($info['width'])->toBe(64);
        expect($info['height'])->toBe(48);
        expect($info['pixels'])->toBe(64 * 48);
        
        $this->rtx->destroyBuffer($bufferId);
    });
    
    it('can list active buffers', function () {
        $buffer1 = $this->rtx->createBuffer(32, 16);
        $buffer2 = $this->rtx->createBuffer(64, 32);
        
        $activeBuffers = $this->rtx->getActiveBuffers();
        expect($activeBuffers)->toHaveCount(2);
        
        $this->rtx->destroyBuffer($buffer1);
        $this->rtx->destroyBuffer($buffer2);
    });
    
    it('can destroy all buffers', function () {
        $this->rtx->createBuffer(32, 16);
        $this->rtx->createBuffer(64, 32);
        
        expect($this->rtx->getActiveBuffers())->toHaveCount(2);
        
        $this->rtx->destroyAllBuffers();
        expect($this->rtx->getActiveBuffers())->toHaveCount(0);
    });
    
    it('handles invalid buffer ID gracefully', function () {
        $info = $this->rtx->getBufferInfo(999);
        expect($info)->toBeNull();
        
        // Should not throw exception
        $this->rtx->destroyBuffer(999);
        expect(true)->toBeTrue(); // Test passes if no exception thrown
    });
    
});

describe('Drawing Operations', function () {
    
    beforeEach(function () {
        skipIfNoCudaGraphics();
        $this->rtx = new RTX();
        $this->bufferId = $this->rtx->createBuffer(128, 32);
    });
    
    afterEach(function () {
        if (isset($this->bufferId)) {
            $this->rtx->destroyBuffer($this->bufferId);
        }
    });
    
    it('can clear buffer', function () {
        $this->rtx->clear($this->bufferId, RTX::BLACK);
        
        $data = $this->rtx->copyBufferToArray($this->bufferId);
        expect($data)->toBeArray();
        expect(count($data))->toBe(128 * 32);
        
        // All pixels should be black (0)
        $nonZero = array_filter($data, fn($x) => $x > 0);
        expect($nonZero)->toHaveCount(0);
    });
    
    it('can draw pixels', function () {
        $this->rtx->clear($this->bufferId, RTX::BLACK);
        $this->rtx->pixel($this->bufferId, 64, 16, RTX::WHITE);
        
        $data = $this->rtx->copyBufferToArray($this->bufferId);
        $pixelValue = $data[16 * 128 + 64];
        
        expect($pixelValue)->toBeGreaterThan(0);
    });
    
    it('ignores out-of-bounds pixels', function () {
        // Should not throw exception for out-of-bounds coordinates
        $this->rtx->pixel($this->bufferId, -1, 16, RTX::WHITE);
        $this->rtx->pixel($this->bufferId, 200, 16, RTX::WHITE);
        $this->rtx->pixel($this->bufferId, 64, -1, RTX::WHITE);
        $this->rtx->pixel($this->bufferId, 64, 100, RTX::WHITE);
        
        expect(true)->toBeTrue(); // Test passes if no exception thrown
    });
    
    it('can draw lines', function () {
        $this->rtx->clear($this->bufferId, RTX::BLACK);
        $this->rtx->line($this->bufferId, 0, 0, 127, 31, RTX::WHITE);
        
        $data = $this->rtx->copyBufferToArray($this->bufferId);
        $nonZero = array_filter($data, fn($x) => $x > 0);
        
        expect($nonZero)->not->toHaveCount(0);
    });
    
    it('can draw rectangles', function () {
        $this->rtx->clear($this->bufferId, RTX::BLACK);
        $this->rtx->rectangle($this->bufferId, 10, 5, 20, 15, RTX::WHITE, false);
        
        $data = $this->rtx->copyBufferToArray($this->bufferId);
        $nonZero = array_filter($data, fn($x) => $x > 0);
        
        expect($nonZero)->not->toHaveCount(0);
    });
    
    it('can draw filled rectangles', function () {
        $this->rtx->clear($this->bufferId, RTX::BLACK);
        $this->rtx->rectangle($this->bufferId, 10, 5, 20, 15, RTX::WHITE, true);
        
        $data = $this->rtx->copyBufferToArray($this->bufferId);
        $nonZero = array_filter($data, fn($x) => $x > 0);
        
        // Filled rectangle should have more pixels than outline
        expect(count($nonZero))->toBeGreaterThan(50);
    });
    
    it('can draw circles', function () {
        $this->rtx->clear($this->bufferId, RTX::BLACK);
        $this->rtx->circle($this->bufferId, 64, 16, 10, RTX::WHITE, false);
        
        $data = $this->rtx->copyBufferToArray($this->bufferId);
        $nonZero = array_filter($data, fn($x) => $x > 0);
        
        expect($nonZero)->not->toHaveCount(0);
    });
    
});

describe('Advanced Effects', function () {
    
    beforeEach(function () {
        skipIfNoCudaGraphics();
        $this->rtx = new RTX();
        $this->bufferId = $this->rtx->createBuffer(128, 32);
    });
    
    afterEach(function () {
        if (isset($this->bufferId)) {
            $this->rtx->destroyBuffer($this->bufferId);
        }
    });
    
    it('can generate plasma effect', function () {
        $this->rtx->plasma($this->bufferId, 1.0, 10.0);
        
        $data = $this->rtx->copyBufferToArray($this->bufferId);
        $nonZero = array_filter($data, fn($x) => $x > 0);
        
        expect($nonZero)->not->toHaveCount(0);
    });
    
    it('can generate mandelbrot fractal', function () {
        $this->rtx->mandelbrot($this->bufferId, 100.0, 50, -0.5, 0.0);
        
        $data = $this->rtx->copyBufferToArray($this->bufferId);
        $nonZero = array_filter($data, fn($x) => $x > 0);
        
        expect($nonZero)->not->toHaveCount(0);
    });
    
    it('can generate particle system', function () {
        $this->rtx->particles($this->bufferId, 256, 0.3, 0.1);
        
        $data = $this->rtx->copyBufferToArray($this->bufferId);
        $nonZero = array_filter($data, fn($x) => $x > 0);
        
        expect($nonZero)->not->toHaveCount(0);
    });
    
    it('validates particle count', function () {
        expect(fn() => $this->rtx->particles($this->bufferId, 0, 0.3, 0.1))
            ->toThrow(InvalidArgumentException::class);
            
        expect(fn() => $this->rtx->particles($this->bufferId, 2000, 0.3, 0.1))
            ->toThrow(InvalidArgumentException::class);
    });
    
    it('can generate wave effect', function () {
        $this->rtx->waves($this->bufferId, 1.0, 2.0, 1.0);
        
        $data = $this->rtx->copyBufferToArray($this->bufferId);
        $nonZero = array_filter($data, fn($x) => $x > 0);
        
        expect($nonZero)->not->toHaveCount(0);
    });
    
});

describe('SSD1306 Integration', function () {
    
    beforeEach(function () {
        skipIfNoCudaGraphics();
        $this->rtx = new RTX();
        $this->bufferId = $this->rtx->createBuffer(128, 32);
    });
    
    afterEach(function () {
        if (isset($this->bufferId)) {
            $this->rtx->destroyBuffer($this->bufferId);
        }
    });
    
    it('can convert to SSD1306 format', function () {
        $this->rtx->clear($this->bufferId, RTX::BLACK);
        $this->rtx->rectangle($this->bufferId, 10, 5, 20, 15, RTX::WHITE, true);
        
        $ssd1306Data = $this->rtx->toSSD1306Format($this->bufferId);
        
        expect($ssd1306Data)->toBeArray();
        // SSD1306 128x32 = 4 pages * 128 columns = 512 bytes
        expect(count($ssd1306Data))->toBe(512);
    });
    
    it('respects threshold in SSD1306 conversion', function () {
        $this->rtx->clear($this->bufferId, 100); // Gray background
        
        $lowThreshold = $this->rtx->toSSD1306Format($this->bufferId, 50);
        $highThreshold = $this->rtx->toSSD1306Format($this->bufferId, 150);
        
        $lowSum = array_sum($lowThreshold);
        $highSum = array_sum($highThreshold);
        
        // Lower threshold should result in more pixels being "on"
        expect($lowSum)->toBeGreaterThan($highSum);
    });
    
});

describe('Performance Monitoring', function () {
    
    beforeEach(function () {
        skipIfNoCudaGraphics();
        $this->rtx = new RTX();
        $this->bufferId = $this->rtx->createBuffer(128, 32);
    });
    
    afterEach(function () {
        if (isset($this->bufferId)) {
            $this->rtx->destroyBuffer($this->bufferId);
        }
    });
    
    it('can measure performance', function () {
        $performance = $this->rtx->measurePerformance(function () {
            $this->rtx->clear($this->bufferId, RTX::BLACK);
            $this->rtx->circle($this->bufferId, 64, 16, 10, RTX::WHITE, true);
        }, 3);
        
        expect($performance)->toBeArray();
        expect($performance)->toHaveKeys(['iterations', 'times', 'average_ms', 'min_ms', 'max_ms', 'fps', 'consistency']);
        expect($performance['iterations'])->toBe(3);
        expect($performance['average_ms'])->toBeGreaterThan(0);
        expect($performance['fps'])->toBeGreaterThan(0);
    });
    
});
