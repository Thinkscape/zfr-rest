<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace ZfrRestTest\Mvc\Asset;

use Zend\View\Model\ViewModel;
use ZfrRest\Mvc\Controller\AbstractRestfulController;
use ZfrRest\Resource\Metadata\ResourceMetadataInterface;
use ZfrRest\Resource\ResourceInterface;

class DummyController extends AbstractRestfulController
{
    /**
     * @param  mixed $resource
     * @return \Zend\View\Model\ViewModel
     */
    public function get($resource)
    {
        return new ViewModel(array('resource' => $resource));
    }

    /**
     * @param mixed                     $data
     * @param ResourceMetadataInterface $metadata
     * @param ResourceInterface         $resource
     *
     * @return ViewModel
     */
    public function post($data, ResourceMetadataInterface $metadata, ResourceInterface $resource)
    {
        return new ViewModel(array('resource' => $resource));
    }
}