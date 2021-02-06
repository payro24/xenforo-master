
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://payro24.ir
 */
namespace payro24\payro24;

use XF\AddOn\AbstractSetup;

class Setup extends AbstractSetup
{
    public function upgrade(array $stepParams = [])
    {
        $this->uninstall();
        $this->install();
    }

    public function install(array $stepParams = [])
    {
        $entity = \XF::em()->create('XF:PaymentProvider');
        $entity->bulkSet(
            [
                'provider_id' => "payro24",
                'provider_class' => "payro24\\payro24\\payro24",
                'addon_id' => "payro24/payro24"
            ]
        );
        $entity->save();
    }

    public function uninstall(array $stepParams = [])
    {
        $entity = \XF::em()->find('XF:PaymentProvider', 'payro24');
        if (!empty($entity)) {
            $entity->delete();
        }
    }
}
